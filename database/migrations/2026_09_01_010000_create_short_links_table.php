<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An index over the KV namespace, not a copy of it.
 *
 * KV lists lexicographically, offers a cursor, and records nothing about when a
 * key was written. Newest-first was built by reading every key and sorting the
 * lot -- which is one 642ms request per thousand keys, and at three thousand
 * links a day that is a minute of Cloudflare round trips per page load inside a
 * week, capped at a ceiling past which the ordering is quietly wrong.
 *
 * So the ordering moves here. Only the key and its age: the value is still read
 * from KV for the rows on screen and never stored, because the Worker resolves
 * against KV and a local copy of a value would be a second answer to the same
 * question. A key deleted from Cloudflare by someone else leaves a row here
 * whose value comes back empty, which is the behaviour a missing value already
 * had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('key', 512);
            // Set here rather than taken from the row's timestamps: a backfill
            // reads what KV metadata knows, which may be nothing at all.
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            // The namespace is per team, so a key is unique within one.
            $table->unique(['team_id', 'key']);
            // The list orders by age within a team, which is this index.
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_links');
    }
};
