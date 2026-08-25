<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\TargetStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\XAccount;
use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    public function __construct(private readonly AnalyticsQueryService $analytics) {}

    /** Headline numbers for the dashboard. */
    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $targets = PostTarget::query()
            ->whereBetween('updated_at', [$from, $to])
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $published = (int) $targets->get(TargetStatus::Published->value, 0);
        $failed = (int) $targets->get(TargetStatus::Failed->value, 0);
        $attempted = $published + $failed;

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'published' => $published,
            'failed' => $failed,
            'pending' => (int) $targets->get(TargetStatus::Pending->value, 0)
                + (int) $targets->get(TargetStatus::Queued->value, 0),
            'success_rate' => $attempted > 0 ? round($published / $attempted * 100, 2) : 0.0,
            'active_accounts' => XAccount::query()->publishable()->count(),
            'scheduled_posts' => Post::query()->where('status', 'scheduled')->count(),
            'daily' => $this->analytics->dailyVolume($from, $to),
            'failures' => $this->analytics->failureBreakdown($from, $to),
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

        return response()->json([
            'top' => $this->analytics->topAccounts($from, $to, (int) $request->query('limit', 20)),
        ]);
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
