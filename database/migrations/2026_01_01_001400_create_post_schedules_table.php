<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recurring plan that stamps out real posts.
 *
 * The composer produces one Post that fires once. Running "three posts a day
 * per account" through that meant a human creating three posts every morning
 * forever, so this table holds the recipe and a command turns each occurrence
 * into an ordinary Post -- which then goes through the same fan-out, limiter
 * and publisher as anything else.
 *
 * The creative is kept as the composer's own payload rather than as columns
 * mirroring `posts`. Those two would drift, and the generator wants to hand the
 * payload straight to ComposerService anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->json('template');            // composer payload: body, media, audience, variants...

            // Recurrence
            $table->json('times');               // ['09:00', '12:30', '18:00'] in `timezone`
            $table->json('days_of_week')->nullable();  // [1..7], null = every day
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->boolean('is_active')->default(true);

            // A slot that has already passed when the generator wakes is skipped
            // rather than fired late -- a backlog of yesterday's posts arriving
            // at once is worse than not sending them.
            $table->unsignedSmallInteger('catch_up_minutes')->default(15);

            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->unsignedInteger('runs_count')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The generator's only query: due, active, oldest first.
            $table->index(['is_active', 'next_run_at'], 'schedule_due_idx');
            $table->index(['team_id', 'is_active']);
        });

        Schema::table('posts', function (Blueprint $table) {
            // Lets a schedule's output be listed, and stops the generator
            // creating the same occurrence twice after a restart.
            $table->foreignId('post_schedule_id')->nullable()->after('campaign_id')
                ->constrained()->nullOnDelete();
            $table->timestamp('occurrence_at')->nullable()->after('scheduled_at');

            $table->unique(['post_schedule_id', 'occurrence_at'], 'schedule_occurrence_unique');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropUnique('schedule_occurrence_unique');
            $table->dropConstrainedForeignId('post_schedule_id');
            $table->dropColumn('occurrence_at');
        });

        Schema::dropIfExists('post_schedules');
    }
};
