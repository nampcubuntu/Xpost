<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 32)->default('s3');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 128);
            $table->string('category', 32)->default('tweet_image');
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->char('sha256', 64);
            $table->string('alt_text', 1000)->nullable();
            $table->boolean('is_ai_generated')->default(false);
            $table->timestamps();

            // media_id is per X app, so we cache a map of {x_app_id: media_id}
            $table->json('x_media_ids')->nullable();

            $table->unique(['team_id', 'sha256']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_media');
    }
};
