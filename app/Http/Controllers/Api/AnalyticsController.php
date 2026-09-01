<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\TargetStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Services\Analytics\AnalyticsQueryService;
use App\Services\Analytics\ClickHouseConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsQueryService $analytics,
        private readonly ClickHouseConnection $clickhouse,
    ) {}

    /** Headline numbers for the dashboard. */
    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        /*
         * Published is dated by when it was published, not by when the row
         * was last written. They agree today only because nothing has
         * touched a target since it went out; the first retry, status
         * correction or backfill that does would silently move posts into
         * the wrong day, and a chart built on that cannot be checked
         * against anything.
         */
        $published = PostTarget::query()
            ->where('status', TargetStatus::Published->value)
            ->whereBetween('published_at', [$from, $to])
            ->count();

        // Failures have no moment of their own, so the last write is the
        // best there is.
        $failed = PostTarget::query()
            ->where('status', TargetStatus::Failed->value)
            ->whereBetween('updated_at', [$from, $to])
            ->count();

        $attempted = $published + $failed;

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'published' => $published,
            'failed' => $failed,
            'pending' => PostTarget::query()
                ->whereIn('status', [TargetStatus::Pending->value, TargetStatus::Queued->value])
                ->count(),
            'success_rate' => $attempted > 0 ? round($published / $attempted * 100, 2) : 0.0,
            'active_accounts' => XAccount::query()->publishable()->count(),
            'scheduled_posts' => Post::query()->where('status', 'scheduled')->count(),
            'daily' => $this->analytics->dailyVolume($from, $to),
            'failures' => $this->analytics->failureBreakdown($from, $to),
            'engagement' => $this->analytics->engagementTotals($from, $to),
            'engagement_daily' => $this->analytics->dailyEngagement($from, $to),
            'coverage' => $this->coverage(),
        ]);
    }

    public function post(Post $post): JsonResponse
    {
        $this->authorize('view', $post);

        return response()->json([
            'progress' => [
                'total' => $post->targets_total,
                'published' => $post->targets_published,
                'failed' => $post->targets_failed,
                'pending' => $post->targets_pending,
            ],
            'engagement' => $this->analytics->postEngagement($post->id),
            'timeline' => $this->analytics->dailyVolume(
                $post->created_at ?? now()->subMonth(),
                now(),
                $post->id,
            ),
        ]);
    }

    public function accounts(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $rows = $this->analytics->topAccounts($from, $to, (int) $request->query('limit', 20));

        // A row identified only by id is a row nobody can act on: the whole
        // point of ranking accounts is knowing which handle to look at.
        $usernames = XAccount::query()
            ->whereKey(array_column($rows, 'account_id'))
            ->pluck('username', 'id');

        return response()->json([
            'top' => array_map(
                static fn (array $row): array => $row + ['username' => $usernames[$row['account_id']] ?? null],
                $rows,
            ),
        ]);
    }

    /** One row per published tweet, the way X Premium shows a post. */
    public function posts(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $rows = $this->analytics->topPosts($from, $to, min((int) $request->query('limit', 25), 100));

        // ClickHouse holds ids; the names live in MySQL. Two lookups for the
        // whole page rather than one per row.
        $accounts = XAccount::query()
            ->whereKey(array_column($rows, 'account_id'))
            ->pluck('username', 'id');

        $posts = Post::query()
            ->whereKey(array_column($rows, 'post_id'))
            ->pluck('body', 'id');

        return response()->json([
            'data' => array_map(static function (array $row) use ($accounts, $posts): array {
                $body = (string) ($posts[$row['post_id']] ?? '');

                return $row + [
                    'username' => $accounts[$row['account_id']] ?? null,
                    'excerpt' => mb_strlen($body) > 90 ? mb_substr($body, 0, 90).'…' : $body,
                ];
            }, $rows),
        ]);
    }

    /**
     * How much of the account pool these numbers actually speak for.
     *
     * Metrics come back only to the account that owns the tweet, so an account
     * whose token has expired contributes nothing -- and a dashboard that
     * averages over the accounts it could reach, with no note of the ones it
     * could not, reads as a statement about all of them. Six of the seven
     * accounts tried on this install answered Unauthorized.
     *
     * @return array{synced: int, publishable: int, clickhouse: bool}
     */
    private function coverage(): array
    {
        return [
            'synced' => XAccount::query()
                ->publishable()
                ->where('last_synced_at', '>=', now()->subDay())
                ->count(),
            'publishable' => XAccount::query()->publishable()->count(),
            // Without it every engagement figure on the page is empty, which
            // looks identical to nobody having engaged.
            'clickhouse' => $this->clickhouse->enabled(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(Request $request): array
    {
        $to = $request->query('to') ? Carbon::parse($request->query('to')) : now();
        $from = $request->query('from')
            ? Carbon::parse($request->query('from'))
            : $to->copy()->subDays(30);

        return [$from->startOfDay(), $to->endOfDay()];
    }
}
