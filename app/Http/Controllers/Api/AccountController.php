<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AccountStatus;
use App\Enums\CredentialType;
use App\Exceptions\XApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\XAccountResource;
use App\Jobs\RefreshAccountToken;
use App\Models\XAccount;
use App\Services\X\TimezoneGuesser;
use App\Services\X\XAdsAccountLister;
use App\Services\X\XAppSelector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(private readonly XAppSelector $apps) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', XAccount::class);

        $accounts = XAccount::query()
            ->with(['app:id,name,tier', 'proxy:id,label,country,health_status', 'groups:id,name,color'])
            ->search($request->query('search'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('app_id'), fn ($q, $id) => $q->where('x_app_id', $id))
            ->when($request->query('group_id'), fn ($q, $id) => $q->whereHas(
                'groups',
                fn ($g) => $g->where('account_groups.id', $id)
            ))
            ->when($request->boolean('needs_reauth'), fn ($q) => $q->where('status', AccountStatus::NeedsReauth->value))
            ->orderBy($this->sortColumn($request), $request->query('direction', 'desc') === 'asc' ? 'asc' : 'desc')
            ->paginate(min((int) $request->query('per_page', 50), 200))
            ->withQueryString();

        return XAccountResource::collection($accounts);
    }

    public function show(XAccount $account): XAccountResource
    {
        $this->authorize('view', $account);

        return XAccountResource::make($account->load(['app', 'proxy', 'groups']));
    }

    public function update(Request $request, XAccount $account): XAccountResource
    {
        $this->authorize('update', $account);

        $data = $request->validate([
            'daily_post_cap' => ['integer', 'min:0', 'max:2000'],
            'status' => [Rule::enum(AccountStatus::class)],
            'timezone' => ['nullable', 'timezone'],
            // X Ads account id, e.g. 18ce55wllne -- lets this account build cards.
            'ads_account_id' => ['nullable', 'string', 'max:32'],
            'proxy_id' => ['nullable', 'integer', Rule::exists('proxies', 'id')],
            'x_app_id' => ['nullable', 'integer', Rule::exists('x_apps', 'id')],
        ]);

        // A timezone typed by a person outranks any later guess run.
        if (array_key_exists('timezone', $data)) {
            $data['timezone_source'] = $data['timezone'] ? 'manual' : null;
        }

        $account->fill($data)->save();

        return XAccountResource::make($account->fresh(['app', 'proxy', 'groups']));
    }

    /**
     * The ads accounts this account can actually reach, asked live.
     *
     * Not stored, and not folded into the accounts list: it is one Ads API call
     * per account, which a page of fifty rows cannot afford and a pool of ten
     * thousand certainly cannot. Fetched only when someone opens the picker.
     *
     * Connecting picks one automatically, which is right when there is exactly
     * one and a guess when there is more than one -- and the wrong guess is
     * only discovered when a card fails at publish.
     */
    public function adsAccounts(XAccount $account, XAdsAccountLister $lister): JsonResponse
    {
        $this->authorize('update', $account);

        if ($account->credential_type !== CredentialType::OAuth1 || blank($account->token_secret)) {
            return response()->json([
                'message' => 'Tài khoản này nối bằng OAuth 2.0. Ads API chỉ nhận OAuth 1.0a — nối lại bằng nút "Kết nối tài khoản".',
                'data' => [],
            ], 422);
        }

        try {
            return response()->json(['data' => $lister->forAccount($account)]);
        } catch (XApiException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => []], 422);
        }
    }

    public function destroy(XAccount $account): JsonResponse
    {
        $this->authorize('delete', $account);

        $account->delete();

        return response()->json(status: 204);
    }

    /**
     * Bulk actions over a selection or a whole filter.
     *
     * Chunked rather than a single mass UPDATE so the observers that maintain
     * assigned_count still fire, and so a 10k selection does not hold one long
     * write lock.
     */
    public function bulk(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $data = $request->validate([
            'action' => ['required', 'in:enable,disable,delete,assign_app,assign_group,remove_group,set_cap,set_timezone,guess_timezone,refresh_token'],
            'account_ids' => ['required', 'array', 'min:1', 'max:10000'],
            'account_ids.*' => ['integer'],
            'app_id' => ['nullable', 'integer', Rule::exists('x_apps', 'id')],
            'group_id' => ['nullable', 'integer', Rule::exists('account_groups', 'id')],
            'daily_post_cap' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'timezone' => ['nullable', 'timezone'],
        ]);

        $affected = 0;

        XAccount::query()
            ->whereIn('id', $data['account_ids'])
            // guess_timezone reads the proxy's country; without this it is one
            // extra query per account across a selection of up to 10,000.
            ->with('proxy:id,country')
            ->chunkById(500, function ($accounts) use ($data, &$affected): void {
                foreach ($accounts as $account) {
                    $this->applyBulkAction($account, $data);
                    $affected++;
                }
            });

        return response()->json(['affected' => $affected, 'action' => $data['action']]);
    }

    private function applyBulkAction(XAccount $account, array $data): void
    {
        match ($data['action']) {
            'enable' => $account->forceFill([
                'status' => AccountStatus::Active,
                'cooldown_until' => null,
                'consecutive_failures' => 0,
            ])->save(),
            'disable' => $account->forceFill(['status' => AccountStatus::Disabled])->save(),
            'delete' => $account->delete(),
            'assign_app' => $account->forceFill(['x_app_id' => $data['app_id']])->save(),
            'assign_group' => $account->groups()->syncWithoutDetaching([$data['group_id']]),
            'remove_group' => $account->groups()->detach($data['group_id']),
            'set_cap' => $account->forceFill(['daily_post_cap' => $data['daily_post_cap']])->save(),
            // Where the account's audience actually is, which is what
            // "post at 09:00 local" resolves against.
            'set_timezone' => $account->forceFill([
                'timezone' => $data['timezone'] ?: null,
                // Marked manual so a later guess run leaves it alone.
                'timezone_source' => $data['timezone'] ? 'manual' : null,
            ])->save(),

            'guess_timezone' => $this->guessTimezone($account),
            'refresh_token' => RefreshAccountToken::dispatch($account->id, $account->team_id),
            default => null,
        };
    }

    /**
     * Infer one account's timezone, leaving a hand-set value alone.
     *
     * X carries no timezone on the user object, so this reads the profile's
     * free-text location and falls back to the proxy's country. A guess that
     * finds nothing leaves the field empty rather than inventing one -- an
     * empty timezone is visible in the UI, a wrong one is not.
     */
    private function guessTimezone(XAccount $account): void
    {
        if ($account->timezone_source === 'manual') {
            return;
        }

        $guess = app(TimezoneGuesser::class)->guess($account);

        if ($guess === null) {
            return;
        }

        $account->forceFill([
            'timezone' => $guess['timezone'],
            'timezone_source' => $guess['source'],
        ])->save();
    }

    /** Spread every unassigned account across the app pool. */
    public function rebalance(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $moved = $this->apps->rebalance((int) $request->user()->current_team_id);

        return response()->json(['assigned' => $moved]);
    }

    /** Counts for the sidebar badges, in one query instead of six. */
    public function stats(): JsonResponse
    {
        $this->authorize('viewAny', XAccount::class);

        $byStatus = XAccount::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return response()->json([
            'total' => (int) $byStatus->sum(),
            'by_status' => collect(AccountStatus::cases())
                ->mapWithKeys(fn (AccountStatus $s) => [$s->value => (int) $byStatus->get($s->value, 0)])
                ->all(),
            'unassigned' => XAccount::query()->whereNull('x_app_id')->count(),
        ]);
    }

    private function sortColumn(Request $request): string
    {
        $allowed = ['created_at', 'username', 'followers_count', 'last_posted_at', 'health_score'];
        $sort = (string) $request->query('sort', 'created_at');

        return in_array($sort, $allowed, true) ? $sort : 'created_at';
    }
}
