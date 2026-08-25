<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable daily rollup of API spend. The live counters live in Redis (see
 * App\Services\X\XRateLimiter); this table is what survives a Redis flush and
 * what the billing/quota screens read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('x_app_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('x_app_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->string('endpoint', 64);
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('successes')->default(0);
            $table->unsignedInteger('rate_limited')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->timestamps();

            $table->unique(['x_app_id', 'usage_date', 'endpoint'], 'app_usage_unique');
            $table->index('usage_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('x_app_usage');
    }
};
