<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `nullcast` and `promoted_only` were the same flag under two names.
 *
 * ComposerService copied one into the other on every save and the publisher
 * OR-ed them back together, so they could never disagree -- two columns doing
 * one job, with a chance of drifting the moment anything wrote only one of
 * them. `promoted_only` survives because that is the word the UI uses and what
 * an advertiser recognises; `nullcast` is X's wire name and stays in
 * TweetPayload where it belongs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('nullcast');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('nullcast')->default(false)->after('promoted_only');
        });

        // Restore the mirror the old code relied on.
        Schema::hasColumn('posts', 'nullcast')
            && DB::table('posts')->update(['nullcast' => DB::raw('promoted_only')]);
    }
};
