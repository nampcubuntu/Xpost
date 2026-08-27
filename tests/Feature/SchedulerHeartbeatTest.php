<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\DispatchDueTargets;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Whether the scheduler is running.
 *
 * It is a separate process from the queue workers, so it can stop while every
 * other part of the stack stays up and reports healthy -- and then nothing is
 * ever dispatched. The symptom is scheduled posts sitting at 0/N, which says
 * nothing about the cause, so the dispatcher leaves a mark each run and the
 * posts page reads it.
 */
class SchedulerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function dispatching_records_that_the_scheduler_ran(): void
    {
        $this->assertNull(DispatchDueTargets::lastRunAt());

        $this->artisan('xpost:dispatch')->assertSuccessful();

        $this->assertNotNull(DispatchDueTargets::lastRunAt());
    }

    #[Test]
    public function a_run_with_nothing_to_do_still_counts(): void
    {
        // An idle scheduler is a working scheduler; only a stopped one matters.
        $this->artisan('xpost:dispatch')->expectsOutputToContain('Dispatched 0');

        $this->assertNotNull(DispatchDueTargets::lastRunAt());
    }

    #[Test]
    public function the_mark_expires_so_a_stopped_scheduler_shows_as_stopped(): void
    {
        /*
         * The TTL is the whole mechanism: the key stops existing a few minutes
         * after the last run, so a reader checks presence rather than deciding
         * for itself what counts as stale.
         */
        $this->artisan('xpost:dispatch')->assertSuccessful();
        $this->assertNotNull(DispatchDueTargets::lastRunAt());

        $this->travel(6)->minutes();

        $this->assertNull(DispatchDueTargets::lastRunAt());
    }

    #[Test]
    public function a_broken_cache_does_not_stop_the_dispatch(): void
    {
        /*
         * Losing the alarm must not lose the publishing -- the heartbeat is
         * written after targets have already been pushed onto the queue, so an
         * exception there would fail a run that had done its actual work.
         *
         * A real Repository over a broken pair of methods, rather than a mock
         * of the whole facade: the dispatch path uses the cache for other
         * things, and blanking all of it tests the test rather than the guard.
         */
        Cache::swap(new class(new ArrayStore) extends Repository
        {
            /** Standing in for the manager, which is what the facade resolves. */
            public function driver(): self
            {
                return $this;
            }

            public function put($key, $value, $ttl = null): bool
            {
                throw new \RuntimeException('cache is down');
            }

            public function get($key, $default = null): mixed
            {
                throw new \RuntimeException('cache is down');
            }
        });

        $this->artisan('xpost:dispatch')->assertSuccessful();

        $this->assertNull(DispatchDueTargets::lastRunAt());
    }

    #[Test]
    public function the_posts_endpoint_reports_it(): void
    {
        // The posts page is where a stalled schedule is noticed, so it is where
        // the answer has to be.
        [, $user] = $this->tenant();

        $this->artisan('xpost:dispatch')->assertSuccessful();

        $this->actingAs($user)
            ->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonPath('scheduler_last_run_at', DispatchDueTargets::lastRunAt());
    }

    #[Test]
    public function the_posts_endpoint_reports_a_stopped_scheduler_as_null(): void
    {
        [, $user] = $this->tenant();

        $this->actingAs($user)
            ->getJson('/api/v1/posts')
            ->assertOk()
            ->assertJsonPath('scheduler_last_run_at', null);
    }
}
