<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The profile's free-text location, mirrored from GET /2/users/me.
 *
 * X API v2 has no timezone field on the user object, so this plus the account's
 * proxy country is everything available to guess one from. Kept verbatim rather
 * than resolved at sync time: the guess rules will change, the raw string will
 * not, and re-guessing without re-calling the API is worth a varchar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('x_accounts', function (Blueprint $table) {
            $table->string('location')->nullable()->after('verified_type');

            // How the timezone got there, so a human-set value is never
            // overwritten by a later guess.
            $table->string('timezone_source', 16)->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('x_accounts', function (Blueprint $table) {
            $table->dropColumn(['location', 'timezone_source']);
        });
    }
};
