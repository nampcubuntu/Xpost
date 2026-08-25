<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 16)->default('#1d9bf0');
            $table->string('description')->nullable();
            $table->unsignedInteger('accounts_count')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        Schema::create('account_group_x_account', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('x_account_id')->constrained()->cascadeOnDelete();

            $table->unique(['account_group_id', 'x_account_id'], 'group_account_unique');
            $table->index('x_account_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_group_x_account');
        Schema::dropIfExists('account_groups');
    }
};
