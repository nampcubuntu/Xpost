<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember the card a target already minted.
 *
 * The card is built inside publish(), which runs again on every retry -- so a
 * target that X rate-limited four times minted five cards and used one. The
 * other four stayed in the ads account forever, and minting is a paid API round
 * trip inside the critical path.
 *
 * Holding the uri on the target makes a retry pick the card back up instead of
 * paying for it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->string('card_uri', 64)->nullable()->after('rendered_media_ids');
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table): void {
            $table->dropColumn('card_uri');
        });
    }
};
