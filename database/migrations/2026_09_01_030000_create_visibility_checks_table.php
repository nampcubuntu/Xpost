<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handles being watched for X restrictions, and the last verdict on each.
 *
 * Its own table rather than columns on x_accounts, which is where this started.
 * The question is asked about names, and the useful ones include handles this
 * install has never connected: one being bought, one that was disconnected
 * after it went quiet, a competitor. Tying the answer to a connected account
 * meant those could not be asked about at all.
 *
 * One row per handle per team, holding the latest answer. A log of every check
 * would be a table nobody reads growing by a row a check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visibility_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Stored lowercase and bare: people paste @handle, a profile URL,
            // and whatever case X happened to show them.
            $table->string('handle', 64);
            // ok | restricted | protected | unknown, null until first checked.
            $table->string('state', 16)->nullable();
            $table->timestamp('checked_at')->nullable();
            // Which test said what, so a verdict can be read rather than
            // trusted.
            $table->json('detail')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'handle']);
            // The list filters by verdict and orders by when it was reached.
            $table->index(['team_id', 'state']);
            $table->index(['team_id', 'checked_at']);
        });

        /*
         * The columns this used to live in. Nothing reads them now -- the page
         * that did asks this table instead -- and a column kept "just in case"
         * is a second answer waiting to disagree with the first.
         */
        Schema::table('x_accounts', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'visibility_state']);
            $table->dropColumn(['visibility_state', 'visibility_checked_at', 'visibility_detail']);
        });
    }

    public function down(): void
    {
        Schema::table('x_accounts', function (Blueprint $table) {
            $table->string('visibility_state', 16)->nullable()->after('health_score');
            $table->timestamp('visibility_checked_at')->nullable()->after('visibility_state');
            $table->json('visibility_detail')->nullable()->after('visibility_checked_at');
            $table->index(['team_id', 'visibility_state']);
        });

        Schema::dropIfExists('visibility_checks');
    }
};
