<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short-lived PKCE handshake records. Kept in MySQL rather than the session so
 * the X callback works even when it lands on a different web node.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_oauth_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('x_app_id')->constrained('x_apps')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('state', 64)->unique();
            $table->text('code_verifier');            // encrypted cast
            $table->string('redirect_to')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x_oauth_states');
    }
};
