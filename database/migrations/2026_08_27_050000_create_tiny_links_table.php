<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What TinyURL gave back for a short link, remembered.
 *
 * Without this, asking TinyURL twice for the same URL mints a second code, and
 * a post already carrying the first one becomes untraceable -- two live links
 * to the same place with nothing saying which is in use where.
 *
 * Keyed by the target rather than by the KV key on purpose: the short link is
 * built from whichever domain is default, so the same key under a different
 * domain is a genuinely different URL and deserves its own code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiny_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('target_url', 500);
            $table->string('tiny_url', 255);
            $table->string('alias', 100)->nullable();
            $table->timestamps();

            // 500 is past MySQL's index limit on utf8mb4, so the uniqueness is
            // held on a hash of the target instead of the target itself.
            $table->char('target_hash', 64);
            $table->unique(['team_id', 'target_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiny_links');
    }
};
