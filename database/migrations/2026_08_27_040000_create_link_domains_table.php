<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The domains a short code can be served from.
 *
 * A single XPOST_LINK_BASE_URL could only ever put one host in front of every
 * link, which is wrong on two counts: a pool this size wants to spread its
 * links across several, and a domain that stops working should be swapped
 * without an .env edit and a deploy.
 *
 * Crucially this is not a property of a key. Every domain routing to the same
 * Worker reads the same namespace, so any code resolves on any of them -- the
 * domain is chosen when a link is used, not when it is created. Nothing here
 * is written to Cloudflare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Host only, no scheme and no path: those are the same for every
            // domain in front of one Worker, and storing them invites one row
            // with a trailing slash and one without.
            $table->string('host');
            $table->string('label')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['team_id', 'host']);
            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_domains');
    }
};
