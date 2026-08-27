<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the numeric App ID that X's developer portal shows.
 *
 * Nothing here knew it, so mail from X naming an app -- "your app 33347024 has
 * been provided with Standard Ads API access" -- could not be matched to any
 * row. Ads API access is granted per app, and connecting accounts through the
 * wrong one produces cards that fail at publish, so guessing is expensive.
 *
 * `ads_api_access` is what actually gates card minting, and unlike the id it
 * can be observed: GET /12/accounts answers 403 for an app without access, and
 * 200 for one with it, whether or not the user owns any ads account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_apps', function (Blueprint $table): void {
            $table->string('provider_app_id', 32)->nullable()->after('name');
            $table->boolean('ads_api_access')->nullable()->after('provider_app_id');
            $table->timestamp('ads_api_checked_at')->nullable()->after('ads_api_access');
        });
    }

    public function down(): void
    {
        Schema::table('x_apps', function (Blueprint $table): void {
            $table->dropColumn(['provider_app_id', 'ads_api_access', 'ads_api_checked_at']);
        });
    }
};
