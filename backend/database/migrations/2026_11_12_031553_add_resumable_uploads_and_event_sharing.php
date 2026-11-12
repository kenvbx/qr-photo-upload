<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $t) {
            $t->softDeletes();
            $t->string('moderation_status', 12)->default('pending')->index();
        });
        Schema::table('upload_sessions', function (Blueprint $t) {
            $t->string('share_token', 64)->nullable()->unique();
            $t->boolean('share_enabled')->default(false);
            $t->timestamp('share_expires_at')->nullable();
            $t->string('brand_color', 7)->default('#285c46');
            $t->string('welcome_message', 500)->nullable();
            $t->string('logo_path')->nullable();
        });
        Schema::create('resumable_uploads', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('upload_session_id')->constrained()->cascadeOnDelete();
            $t->string('secret_hash', 64);
            $t->string('name');
            $t->string('mime', 32);
            $t->unsignedInteger('bytes');
            $t->unsignedInteger('offset')->default(0);
            $t->string('sha256', 64);
            $t->json('result')->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resumable_uploads');
        Schema::table('photos', function (Blueprint $t) {
            $t->dropIndex(['moderation_status']);
            $t->dropSoftDeletes();
            $t->dropColumn('moderation_status');
        });
        Schema::table('upload_sessions', function (Blueprint $t) {
            $t->dropUnique(['share_token']);
            $t->dropColumn(['share_token', 'share_enabled', 'share_expires_at', 'brand_color', 'welcome_message', 'logo_path']);
        });
    }
};
