<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('label')->nullable();
            $table->string('scheme', 16)->default('http');
            $table->string('host');
            $table->unsignedSmallInteger('port');
            $table->string('username')->nullable();
            $table->text('password')->nullable();          // encrypted cast
            $table->char('country', 2)->nullable();
            $table->unsignedSmallInteger('max_accounts')->default(50);
            $table->unsignedSmallInteger('assigned_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('health_status', 16)->default('unknown'); // unknown|healthy|degraded|dead
            $table->unsignedSmallInteger('fail_count')->default(0);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'host', 'port', 'username']);
            $table->index(['team_id', 'is_active', 'assigned_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxies');
    }
};
