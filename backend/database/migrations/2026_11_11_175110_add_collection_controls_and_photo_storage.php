<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table) {
            $table->string('password_hash')->nullable();
            $table->unsignedBigInteger('quota_bytes')->default(1048576000);
            $table->unsignedSmallInteger('retention_days')->nullable();
        });
        Schema::table('photos', function (Blueprint $table) {
            $table->string('disk', 16)->default('local');
            $table->string('original_name')->nullable();
            $table->timestamp('delete_after')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('photos', fn (Blueprint $table) => $table->dropIndex(['delete_after']));
        Schema::table('photos', fn (Blueprint $table) => $table->dropColumn(['disk', 'original_name', 'delete_after']));
        Schema::table('upload_sessions', fn (Blueprint $table) => $table->dropColumn(['password_hash', 'quota_bytes', 'retention_days']));
    }
};
