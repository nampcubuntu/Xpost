<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per composed creative. The fan-out onto individual accounts lives in
 * post_targets; this table stays small and is safe to query interactively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Composer fields
            $table->string('name', 255)->nullable();          // "Untitled ad (optional)"
            $table->text('body')->nullable();                 // the tweet text, <= 280 weighted
            $table->string('reply_settings', 24)->default('everyone');

            // Destination card
            $table->string('destination_type', 16)->default('none');
            $table->string('website_url', 2048)->nullable();
            $table->string('headline', 70)->nullable();
            $table->string('app_store_url', 2048)->nullable();
            $table->string('app_country_code', 8)->nullable();

            // Flags shown in the composer
            $table->boolean('is_ai_generated')->default(false);
            $table->boolean('promoted_only')->default(false);
            $table->boolean('nullcast')->default(false);

            // Content variation
            $table->string('variant_strategy', 24)->default('same');

            // Scheduling
            $table->string('schedule_mode', 32)->default('immediate');
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('window_ends_at')->nullable();
            $table->unsignedInteger('jitter_seconds')->default(0);
            $table->unsignedInteger('per_minute_cap')->nullable();

            // Lifecycle + denormalised counters kept in step by the target observer
            $table->string('status', 24)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('targets_total')->default(0);
            $table->unsignedInteger('targets_pending')->default(0);
            $table->unsignedInteger('targets_published')->default(0);
            $table->unsignedInteger('targets_failed')->default(0);

            $table->json('audience')->nullable();   // {groups:[], accounts:[], filters:{}}
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'status', 'scheduled_at']);
            $table->index(['team_id', 'created_at']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('post_media_post', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_media_id')->constrained('post_media')->cascadeOnDelete();
            $table->unsignedTinyInteger('position')->default(0);

            $table->unique(['post_id', 'post_media_id'], 'post_media_unique');
        });

        Schema::create('post_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('headline', 70)->nullable();
            $table->string('website_url', 2048)->nullable();
            $table->json('media_ids')->nullable();
            $table->unsignedSmallInteger('weight')->default(1);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index(['post_id', 'weight']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_variants');
        Schema::dropIfExists('post_media_post');
        Schema::dropIfExists('posts');
    }
};
