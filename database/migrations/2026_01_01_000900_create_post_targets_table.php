<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fan-out table: one row per (post, account). A 10k-account blast is 10k
 * rows, so this is the only table that grows fast. The dispatcher claims work
 * from it with FOR UPDATE SKIP LOCKED against the (status, scheduled_at) index,
 * which is why status is the leading column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('x_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_variant_id')->nullable()->constrained('post_variants')->nullOnDelete();

            // Snapshot of what was actually sent, resolved at fan-out time so a
            // later edit of the post never rewrites history.
            $table->text('rendered_body');
            $table->string('rendered_headline', 70)->nullable();
            $table->string('rendered_url', 2048)->nullable();
            $table->json('rendered_media_ids')->nullable();

            $table->string('status', 16)->default('pending');
            $table->timestamp('scheduled_at')->index();

            // Claim/lease bookkeeping
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->string('claimed_by', 64)->nullable();

            // Result
            $table->foreignId('x_app_id')->nullable()->constrained('x_apps')->nullOnDelete();
            $table->string('x_tweet_id', 32)->nullable();
            $table->string('permalink', 255)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedSmallInteger('duration_ms')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['post_id', 'x_account_id'], 'post_target_unique');
            $table->index(['status', 'scheduled_at'], 'target_dispatch_idx');
            $table->index(['post_id', 'status']);
            $table->index(['team_id', 'published_at']);
            $table->index(['x_account_id', 'published_at']);
            $table->index('x_tweet_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_targets');
    }
};
