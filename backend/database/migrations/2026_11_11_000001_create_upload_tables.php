<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('title', 120);
            $t->string('token', 64)->unique();
            $t->timestamp('expires_at')->index();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('upload_session_id')->constrained()->cascadeOnDelete();
            $t->string('path')->unique();
            $t->string('mime', 40);
            $t->unsignedBigInteger('bytes');
            $t->unsignedInteger('width');
            $t->unsignedInteger('height');
            $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 50);
            $t->unsignedBigInteger('resource_id');
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('photos');
        Schema::dropIfExists('upload_sessions');
    }
};
