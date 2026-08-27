<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give each team its own Cloudflare KV namespace.
 *
 * The namespace id was one global setting, so every team read and wrote the
 * same store: any admin could list another team's short links and delete them
 * -- which does not unpublish anything, it makes a link in somebody else's live
 * post stop resolving, quietly, for everyone who clicks it. It was the only
 * tenancy hole left after every model got a team scope.
 *
 * Isolation belongs to Cloudflare here rather than to a WHERE clause somebody
 * can forget: two teams with different namespaces cannot reach each other's
 * keys even through a bug in this codebase. It also leaves the Worker alone --
 * keys keep their shape, so links already published keep resolving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->string('cloudflare_kv_namespace_id', 64)->nullable()->after('daily_budget_usd');
        });

        /*
         * The existing namespace belongs to whoever is already using it. Moving
         * it onto that team keeps today working; every other team starts with
         * none, which reads as "not configured" rather than as a share.
         */
        if (filled($namespace = config('xpost.links.namespace_id'))) {
            DB::table('teams')->orderBy('id')->limit(1)
                ->update(['cloudflare_kv_namespace_id' => $namespace]);
        }
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('cloudflare_kv_namespace_id');
        });
    }
};
