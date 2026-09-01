<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AccountStatus;
use App\Enums\CredentialType;
use App\Exceptions\XApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\XAccountResource;
use App\Jobs\CheckAccountVisibility;
use App\Jobs\RefreshAccountToken;
use App\Models\AccountGroup;
use App\Models\XAccount;
use App\Services\Proxies\ProxyAssigner;
use App\Services\X\ShadowbanChecker;
use App\Services\X\TimezoneGuesser;
use App\Services\X\XAdsAccountLister;
use App\Services\X\XAppSelector;
use App\Support\TeamContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(private readonly XAppSelector $apps) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', XAccount::class);

        $accounts = XAccount::query()
            ->with(['app:id,name,tier', 'proxy:id,label,host,port,country,health_status', 'groups:id,name,color'])
            ->search($request->query('search'))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('app_id'), fn ($q, $id) => $q->where('x_app_id', $id))
            ->when($request->query('group_id'), fn ($q, $id) => $q->whereHas(
                'groups',
                fn ($g) => $g->where('account_groups.id', $id)
            ))
            ->when($request->boolean('needs_reauth'), fn ($q) => $q->where('status', AccountStatus::NeedsReauth->value))
            // Filtered here rather than in the browser: a pool of a thousand
            // shows fifty at a time, and "the restricted ones" is a question
            // about the pool.
            ->when($request->query('visibility'), fn ($q, $v) => $q->where('visibility_state', $v))
            // The accounts still leaving from the server's own IP, which is the
            // set the proxy picker is nearly always after.
            ->when($request->boolean('without_proxy'), fn ($q) => $q->whereNull('proxy_id'))
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
            'timezone' => ['nullable', 'timezone:all_with_bc'],
            // X Ads account id, e.g. 18ce55wllne -- lets this account build cards.
            'ads_account_id' => ['nullable', 'string', 'max:32'],
            // Scoped to the team, not just to the table: an id from a browser
            // must not put this account behind another team's proxy or app.
            'proxy_id' => ['nullable', 'integer', Rule::exists('proxies', 'id')->where('team_id', TeamContext::id())],
            'x_app_id' => ['nullable', 'integer', Rule::exists('x_apps', 'id')->where('team_id', TeamContext::id())],
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
    public function adsAccounts(Request $request, XAccount $account, XAdsAccountLister $lister): JsonResponse
    {
        $this->authorize('update', $account);

        if ($account->credential_type !== CredentialType::OAuth1 || blank($account->token_secret)) {
            return response()->json([
                'message' => 'Tài khoản này nối bằng OAuth 2.0. Ads API chỉ nhận OAuth 1.0a — nối lại bằng nút "Kết nối tài khoản".',
                'data' => [],
            ], 422);
        }

        /*
         * A specific id, checked rather than looked up.
         *
         * X's account list leaves out ads accounts that work: one was missing
         * from the list and answered NOT_FOUND when asked for by id, while
         * reporting ACCOUNT_ADMIN and minting cards without complaint. So
         * someone who knows their id has to be able to say it.
         */
        if ($request->filled('ads_account_id')) {
            $verified = $lister->verify($account, (string) $request->query('ads_account_id'));

            return $verified === null
                ? response()->json([
                    'message' => 'Tài khoản này không có quyền quản trị trên ads account đó.',
                    'data' => [],
                ], 422)
                : response()->json(['data' => [$verified]]);
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
    public function bulk(Request $request, ProxyAssigner $proxies): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        /*
         * Every id is checked against this team, not just against the table.
         * These arrive from a browser, and `exists` alone would let one team
         * put its accounts behind another team's proxy or app.
         */
        $team = TeamContext::id();
        $onTeam = fn (string $table) => Rule::exists($table, 'id')->where('team_id', $team);

        $data = $request->validate([
            'action' => ['required', 'in:enable,disable,delete,assign_app,assign_group,remove_group,set_cap,set_timezone,guess_timezone,refresh_token,assign_proxy,spread_proxy,clear_proxy'],
            'account_ids' => ['required', 'array', 'min:1', 'max:10000'],
            'account_ids.*' => ['integer'],
            'app_id' => ['nullable', 'integer', $onTeam('x_apps')],
            'group_id' => ['nullable', 'integer', $onTeam('account_groups')],
            'proxy_id' => ['nullable', 'integer', $onTeam('proxies')],
            'daily_post_cap' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'timezone' => ['nullable', 'timezone:all_with_bc'],
        ]);

        if ($data['action'] === 'assign_proxy' && ($data['proxy_id'] ?? null) === null) {
            return response()->json(['message' => 'Chưa chọn proxy nào.'], 422);
        }

        $affected = 0;

        XAccount::query()
            ->whereIn('id', $data['account_ids'])
            // guess_timezone reads the proxy's country; without this it is one
            // extra query per account across a selection of up to 10,000.
            ->with('proxy:id,country')
            ->chunkById(500, function ($accounts) use ($data, $proxies, &$affected): void {
                foreach ($accounts as $account) {
                    $this->applyBulkAction($account, $data, $proxies);
                    $affected++;
                }
            });

        /*
         * The counter the group list renders, brought back in step.
         *
         * assign_group and remove_group move pivot rows, which fires nothing --
         * only the group controller's own sync endpoint was recounting, and
         * this is the path the accounts toolbar actually takes. Five hundred
         * accounts went into a group and the group went on saying "0".
         *
         * Once after the loop rather than once per account: it is a COUNT, and
         * a selection here can be ten thousand.
         */
        if (in_array($data['action'], ['assign_group', 'remove_group'], true) && ($data['group_id'] ?? null)) {
            AccountGroup::query()->find($data['group_id'])?->syncCount();
        }

        return response()->json([
            'affected' => $affected,
            'action' => $data['action'],
            // How many asked for a proxy and found the pool full. Silence here
            // would read as "done" on accounts that still share one IP.
            'unassigned' => $proxies->unassigned,
        ]);
    }

    private function applyBulkAction(XAccount $account, array $data, ProxyAssigner $proxies): void
    {
        match ($data['action']) {
            /*
             * Which IP this account walks out of.
             *
             * spread_proxy is the one that matters at scale: the reason for
             * holding proxies at all is that a thousand accounts do not leave
             * from one address, and sharing them out by hand is a thousand
             * dropdowns. assign_proxy is for the cases where a particular
             * account has to sit on a particular exit.
             */
            'assign_proxy' => $account->forceFill(['proxy_id' => $data['proxy_id']])->save(),
            'spread_proxy' => $account->forceFill([
                'proxy_id' => $proxies->next($account->team_id)?->id ?? $account->proxy_id,
            ])->save(),
            'clear_proxy' => $account->forceFill(['proxy_id' => null])->save(),

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
    /**
     * Whether X is limiting these accounts, asked now.
     *
     * Queued rather than answered inline: the check is two Sorsa calls per
     * account against a flat twenty requests a second, so a thousand accounts
     * is a hundred seconds at best. The page marks them queued and watches the
     * verdicts arrive, the same way the proxy checks do.
     */
    public function checkVisibility(Request $request, ShadowbanChecker $checker): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        if (! $checker->isConfigured()) {
            return response()->json([
                'message' => 'Chưa cấu hình SORSA_API_KEY trong .env, nên không kiểm tra được.',
            ], 422);
        }

        /*
         * Ticked rows, or a pasted list of handles.
         *
         * The list is there because the pool is a thousand accounts over
         * twenty-one pages, and "check these forty" is a question about a list
         * someone already has -- not about what happens to be on screen.
         */
        $data = $request->validate([
            'account_ids' => ['array', 'max:10000'],
            'account_ids.*' => ['integer'],
            'usernames' => ['array', 'max:10000'],
            'usernames.*' => ['string', 'max:64'],
        ]);

        $handles = collect($data['usernames'] ?? [])
            // People paste what they have: @handle, a profile URL, a column
            // from a spreadsheet. The column stores them bare.
            ->map(fn (string $raw) => strtolower(trim(ltrim(trim($raw), '@'))))
            ->map(fn (string $h) => str_contains($h, '/') ? strtolower(basename(parse_url($h, PHP_URL_PATH) ?: $h)) : $h)
            ->filter()
            ->unique()
            ->values();

        if (($data['account_ids'] ?? []) === [] && $handles->isEmpty()) {
            return response()->json(['message' => 'Chưa chọn tài khoản nào và cũng chưa dán handle nào.'], 422);
        }

        $queued = 0;
        $found = [];

        XAccount::query()
            ->where(function ($q) use ($data, $handles): void {
                $q->when($data['account_ids'] ?? [], fn ($w, $ids) => $w->orWhereIn('id', $ids))
                    ->when($handles->isNotEmpty(), fn ($w) => $w->orWhereIn(
                        DB::raw('LOWER(username)'), $handles->all()
                    ));
            })
            // Chunked so ten thousand ids do not become one query the size of
            // the selection.
            ->chunkById(500, function ($accounts) use (&$queued, &$found): void {
                foreach ($accounts as $account) {
                    CheckAccountVisibility::dispatch($account->id, $account->team_id);
                    $found[] = strtolower((string) $account->username);
                    $queued++;
                }
            });

        /*
         * Named, not counted. "38 of 40 queued" leaves someone holding two
         * handles they cannot find -- the same failure the proxy and link
         * importers were built to avoid.
         */
        return response()->json([
            'queued' => $queued,
            'not_found' => $handles->reject(fn (string $h) => in_array($h, $found, true))->values(),
        ], 202);
    }

    /** The pool's visibility at a glance, for the banner on the page. */
    public function visibilityStats(ShadowbanChecker $checker): JsonResponse
    {
        $this->authorize('viewAny', XAccount::class);

        $byState = XAccount::query()
            ->selectRaw('visibility_state, COUNT(*) as aggregate')
            ->groupBy('visibility_state')
            ->pluck('aggregate', 'visibility_state');

        $known = ['ok', 'restricted', 'protected', 'unknown'];

        $counts = [];

        foreach ($known as $state) {
            $counts[$state] = (int) $byState->get($state, 0);
        }

        /*
         * Never asked, counted as what is left rather than by key.
         *
         * pluck turns a null column into an empty-string key, so get('') and
         * get(null) both resolve to the same entry -- and adding them reported
         * 2,046 unchecked accounts out of 1,026. Subtracting from the total
         * cannot double count whatever the driver decides to call null.
         */
        $counts['unchecked'] = max(0, (int) $byState->sum() - array_sum($counts));

        return response()->json([
            'configured' => $checker->isConfigured(),
            'total' => (int) $byState->sum(),
            'by_state' => $counts,
        ]);
    }

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
