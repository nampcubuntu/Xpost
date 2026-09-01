<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CheckAccountVisibility;
use App\Models\VisibilityCheck;
use App\Models\XAccount;
use App\Services\X\ShadowbanChecker;
use App\Support\TeamContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles being watched for X restrictions.
 *
 * Its own list rather than a column on the account pool. The question is asked
 * about names, and the useful ones include handles this install has never
 * connected -- one being bought, one disconnected after it went quiet, a
 * competitor. Requiring a connected account meant those could not be asked
 * about at all.
 */
class VisibilityController extends Controller
{
    public function index(Request $request, ShadowbanChecker $checker): JsonResponse
    {
        $this->authorize('viewAny', XAccount::class);

        $rows = VisibilityCheck::query()
            ->when($request->query('search'), fn ($q, $term) => $q->where('handle', 'like', '%'.$term.'%'))
            ->when($request->query('state'), fn ($q, $state) => $state === 'unchecked'
                ? $q->whereNull('state')
                : $q->where('state', $state))
            ->uncheckedFirst()
            ->paginate(min((int) $request->query('per_page', 50), 200))
            ->withQueryString();

        $byState = VisibilityCheck::query()
            ->selectRaw('state, COUNT(*) as aggregate')
            ->groupBy('state')
            ->pluck('aggregate', 'state');

        $counts = [];

        foreach (['ok', 'restricted', 'protected', 'unknown'] as $state) {
            $counts[$state] = (int) $byState->get($state, 0);
        }

        /*
         * Never asked, counted as what is left rather than by key: pluck turns
         * a null column into an empty-string key, and reading it by name once
         * reported more unchecked rows than there were rows.
         */
        $counts['unchecked'] = max(0, (int) $byState->sum() - array_sum($counts));

        return response()->json([
            'data' => $rows->through(fn (VisibilityCheck $row) => [
                'id' => $row->id,
                'handle' => $row->handle,
                'state' => $row->state,
                'checked_at' => $row->checked_at?->toIso8601String(),
                'detail' => $row->detail,
            ])->items(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
            ],
            'configured' => $checker->isConfigured(),
            'total' => (int) $byState->sum(),
            'by_state' => $counts,
        ]);
    }

    /**
     * Add handles to the list, and check them straight away.
     *
     * Adding without checking would be a list of names and no answers, which is
     * not why anyone opens this page.
     */
    public function store(Request $request, ShadowbanChecker $checker): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $data = $request->validate([
            'handles' => ['required', 'array', 'min:1', 'max:5000'],
            'handles.*' => ['string', 'max:200'],
        ]);

        $handles = collect($data['handles'])
            ->map(fn (string $raw) => VisibilityCheck::normalise($raw))
            // X handles are letters, digits and underscore, up to 15. Anything
            // else is a mis-paste, and naming it beats storing a row that can
            // never come back with an answer.
            ->filter(fn (string $h) => $h !== '')
            ->unique()
            ->values();

        [$valid, $invalid] = $handles->partition(fn (string $h) => preg_match('/^\w{1,15}$/', $h) === 1);

        $teamId = (int) TeamContext::id();
        $now = now();

        VisibilityCheck::query()->upsert(
            $valid->map(fn (string $h) => [
                'team_id' => $teamId,
                'handle' => $h,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all(),
            ['team_id', 'handle'],
            // A handle already on the list keeps its last verdict until the new
            // check answers, rather than blanking to "chưa kiểm tra".
            ['updated_at'],
        );

        $rows = VisibilityCheck::query()->whereIn('handle', $valid)->get(['id', 'team_id']);

        if ($checker->isConfigured()) {
            foreach ($rows as $row) {
                CheckAccountVisibility::dispatch($row->id, $row->team_id);
            }
        }

        return response()->json([
            'added' => $valid->count(),
            'queued' => $checker->isConfigured() ? $rows->count() : 0,
            'configured' => $checker->isConfigured(),
            // Named, not counted: "38 of 40" leaves someone holding two lines
            // they cannot find in a paste of forty.
            'invalid' => $invalid->values(),
        ], 202);
    }

    /** Ask again about rows already on the list. */
    public function check(Request $request, ShadowbanChecker $checker): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        if (! $checker->isConfigured()) {
            return response()->json([
                'message' => 'Chưa cấu hình SORSA_API_KEY trong .env, nên không kiểm tra được.',
            ], 422);
        }

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
        ]);

        $queued = 0;

        VisibilityCheck::query()
            ->whereKey($data['ids'])
            ->chunkById(500, function ($rows) use (&$queued): void {
                foreach ($rows as $row) {
                    CheckAccountVisibility::dispatch($row->id, $row->team_id);
                    $queued++;
                }
            });

        return response()->json(['queued' => $queued], 202);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->authorize('create', XAccount::class);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:5000'],
            'ids.*' => ['integer'],
        ]);

        $deleted = VisibilityCheck::query()->whereKey($data['ids'])->delete();

        return response()->json(['deleted' => $deleted]);
    }
}
