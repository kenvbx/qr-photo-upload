<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->string('thumbnail_status', 16)->default('pending')->index();
            $table->unsignedSmallInteger('thumbnail_width')->nullable();
            $table->unsignedSmallInteger('thumbnail_height')->nullable();
            $table->unsignedInteger('thumbnail_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->dropIndex(['thumbnail_status']);
            $table->dropColumn(['thumbnail_status', 'thumbnail_width', 'thumbnail_height', 'thumbnail_bytes']);
        });
    }
};
