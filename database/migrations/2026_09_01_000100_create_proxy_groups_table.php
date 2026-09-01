<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to put a supplier's delivery.
 *
 * Proxies arrive in batches of hundreds and the only thing telling two batches
 * apart is the host, which is exactly what nobody remembers. Shaped like
 * account_groups because it is the same problem and the pages should not need
 * two different explanations -- many-to-many for the same reason: a line is
 * both "Nhà cung cấp A" and "VN dân cư", and being forced to pick one is how a
 * grouping stops getting used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 16)->default('#1d9bf0');
            $table->string('description')->nullable();
            // Denormalised so the group list does not COUNT(*) per row.
            $table->unsignedInteger('proxies_count')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'name']);
        });

        Schema::create('proxy_group_proxy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proxy_group_id')->constrained()->cascadeOnDelete();
            // Deleting a proxy takes its memberships with it, the same way it
            // now releases the accounts behind it.
            $table->foreignId('proxy_id')->constrained('proxies')->cascadeOnDelete();

            $table->unique(['proxy_group_id', 'proxy_id'], 'proxy_group_member_unique');
            // The list filters by group, so the join is read from this side too.
            $table->index('proxy_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_group_proxy');
        Schema::dropIfExists('proxy_groups');
    }
};
