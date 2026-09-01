<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What X's own search says about an account.
 *
 * On the row rather than in a history table: the question anyone asks is "is
 * this account reaching anyone right now", and one answer per account is that
 * question. A log of every check would be a table nobody reads growing by a
 * thousand rows a sweep.
 *
 * checked_at is separate from the verdict on purpose. A verdict with no date is
 * a claim about now made from whenever, which the proxy page already learned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_accounts', function (Blueprint $table) {
            // ok | restricted | protected | unknown, or null for never checked.
            $table->string('visibility_state', 16)->nullable()->after('health_score');
            $table->timestamp('visibility_checked_at')->nullable()->after('visibility_state');
            // Which test said what, so a verdict can be read rather than
            // trusted.
            $table->json('visibility_detail')->nullable()->after('visibility_checked_at');

            // The list filters by it, and a sweep orders by the oldest check.
            $table->index(['team_id', 'visibility_state']);
        });
    }

    public function down(): void
    {
        Schema::table('x_accounts', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'visibility_state']);
            $table->dropColumn(['visibility_state', 'visibility_checked_at', 'visibility_detail']);
        });
    }
};
