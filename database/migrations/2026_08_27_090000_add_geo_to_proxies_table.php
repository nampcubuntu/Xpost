<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a proxy actually comes out.
     *
     * `country` was already here and typed by hand, which makes it a note
     * rather than a fact: a seller's label and the exit IP disagree often
     * enough that assigning accounts by country off the label is guesswork.
     * The check already talks to the internet through the proxy, so it can ask
     * where that request appeared to come from and store the answer instead.
     *
     * exit_ip is kept alongside because it is the part that identifies the
     * proxy to X. Two rows with different credentials that resolve to the same
     * exit IP are one IP as far as X's rate limits and clustering care.
     */
    public function up(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->string('exit_ip', 45)->nullable()->after('port');
            $table->string('region')->nullable()->after('country');
            $table->string('city')->nullable()->after('region');
            $table->timestamp('geo_checked_at')->nullable()->after('city');

            // Answering "which accounts share an exit IP" without a table scan.
            $table->index(['team_id', 'exit_ip']);
        });
    }

    public function down(): void
    {
        Schema::table('proxies', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'exit_ip']);
            $table->dropColumn(['exit_ip', 'region', 'city', 'geo_checked_at']);
        });
    }
};
