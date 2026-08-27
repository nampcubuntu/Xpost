<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the fair-share claim rank without a filesort.
 *
 * The dispatcher ranks due work with ROW_NUMBER() OVER (PARTITION BY team_id
 * ORDER BY scheduled_at). The existing index is (status, scheduled_at), which
 * finds the rows but leaves them in the wrong order for the partition, so the
 * whole due set is sorted before it can be ranked. This one hands them over
 * already grouped by team and ordered within it.
 *
 * EXPLAIN still reports a filesort afterwards, and that one is unavoidable: it
 * belongs to the outer ORDER BY over the derived table. The difference is what
 * it sorts -- at most a run's worth of rows rather than every pending target
 * in the pool.
 *
 * Harmless at a thousand rows and expensive at a million: the dispatcher is the
 * one query that runs every minute against the largest table in the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->index(['status', 'team_id', 'scheduled_at'], 'target_fair_share_idx');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropIndex('target_fair_share_idx');
        });
    }
};
