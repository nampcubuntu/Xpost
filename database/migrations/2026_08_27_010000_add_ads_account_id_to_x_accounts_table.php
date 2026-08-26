<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The X Ads account an X account can build cards under.
 *
 * A video_website card is the only way to publish a video with a tappable
 * "From <domain>" row, and cards are created through the Ads API, which scopes
 * everything to an ads account id like `18ce55wllne`. Accounts without one
 * publish the ordinary way, so this stays nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_accounts', function (Blueprint $table) {
            $table->string('ads_account_id', 32)->nullable()->after('x_app_id');
        });
    }

    public function down(): void
    {
        Schema::table('x_accounts', function (Blueprint $table) {
            $table->dropColumn('ads_account_id');
        });
    }
};
