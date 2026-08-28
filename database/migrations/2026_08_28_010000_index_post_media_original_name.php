<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uploaded video is filed under a generated number, and MediaName checks
     * that the number is free before using it. Without an index that check is a
     * full scan of the library on every upload -- cheap at a hundred rows and
     * not at a hundred thousand, which is where a thousand accounts running
     * campaigns puts it.
     *
     * Not unique: photos keep the names they arrived with, and two people can
     * upload holiday.jpg.
     */
    public function up(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->index('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('post_media', function (Blueprint $table) {
            $table->dropIndex(['original_name']);
        });
    }
};
