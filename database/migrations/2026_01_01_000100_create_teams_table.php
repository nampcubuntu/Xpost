<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh');
            $table->unsignedInteger('account_quota')->default(10_000);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('member'); // owner|admin|editor|viewer
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_team_id')->nullable()->after('password');
            $table->string('locale', 8)->default('vi')->after('current_team_id');
            $table->string('timezone', 64)->default('Asia/Ho_Chi_Minh')->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['current_team_id', 'locale', 'timezone']);
        });

        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
