<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('x_app_id')->nullable()->constrained('x_apps')->nullOnDelete();
            $table->foreignId('proxy_id')->nullable()->constrained('proxies')->nullOnDelete();

            // Identity mirrored from GET /2/users/me
            $table->string('x_user_id', 32);
            $table->string('username', 64);
            $table->string('name')->nullable();
            $table->string('avatar_url')->nullable();
            $table->unsignedInteger('followers_count')->default(0);
            $table->unsignedInteger('following_count')->default(0);
            $table->unsignedInteger('tweet_count')->default(0);
            $table->string('verified_type', 16)->nullable();
            $table->string('timezone', 64)->nullable();

            // Credentials -- every token column uses an encrypted cast
            $table->string('credential_type', 16)->default('oauth2');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->text('token_secret')->nullable();      // oauth1 only
            $table->json('scopes')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('token_refreshed_at')->nullable();

            // Operational state
            $table->string('status', 24)->default('active');
            $table->unsignedTinyInteger('health_score')->default(100);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamp('last_posted_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedSmallInteger('daily_post_cap')->default(50);
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestamp('cooldown_until')->nullable();

            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'x_user_id']);
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'username']);
            $table->index(['status', 'token_expires_at']);   // token refresh sweeper
            $table->index(['x_app_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x_accounts');
    }
};
