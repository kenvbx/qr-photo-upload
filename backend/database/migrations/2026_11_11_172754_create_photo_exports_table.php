<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('upload_session_id')->constrained()->cascadeOnDelete();
            $table->json('photo_ids');
            $table->string('fingerprint', 64);
            $table->string('status', 16)->default('queued');
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['upload_session_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_exports');
    }
};
