<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pool of X developer apps. Every account is bound to one app; spreading
 * accounts over many apps is what keeps per-app POST /2/tweets caps from
 * becoming the bottleneck at a few thousand accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('tier', 16)->default('basic');

            // OAuth 2.0 (PKCE, user context)
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();     // encrypted cast

            // OAuth 1.0a (signing keys for imported user tokens)
            $table->string('consumer_key')->nullable();
            $table->text('consumer_secret')->nullable();   // encrypted cast

            $table->string('callback_url')->nullable();
            $table->json('scopes')->nullable();

            // Budgeting
            $table->unsignedInteger('daily_post_cap')->default(100);
            $table->unsignedInteger('monthly_post_cap')->default(3_000);
            $table->unsignedInteger('monthly_posts_used')->default(0);
            $table->date('usage_period_started_on')->nullable();
            $table->unsignedSmallInteger('max_accounts')->default(500);
            $table->unsignedSmallInteger('assigned_count')->default(0);
            $table->unsignedTinyInteger('weight')->default(1);

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'name']);
            $table->index(['team_id', 'is_active', 'assigned_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x_apps');
    }
};
