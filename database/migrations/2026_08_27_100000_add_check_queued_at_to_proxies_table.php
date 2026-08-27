<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A check that has been asked for but has not answered yet.
     *
     * Checking a selection runs on the queue -- a dead proxy takes fifteen
     * seconds to admit it, and a hundred of them will not fit in an HTTP
     * request. So the row needs a state between "you clicked" and "here is the
     * verdict", or the page has nothing to show and looks like it ignored you.
     *
     * Deliberately not a health_status value: the previous verdict stays
     * readable while the new one is being worked out.
     */
    public function up(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->timestamp('check_queued_at')->nullable()->after('last_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->dropColumn('check_queued_at');
        });
    }
};
