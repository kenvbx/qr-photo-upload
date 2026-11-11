<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->string('sha256', 64)->nullable();
            $table->unique(['upload_session_id', 'sha256']);
        });
        Schema::create('upload_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_session_id')->constrained()->cascadeOnDelete();
            $table->uuid('upload_key');
            $table->string('fingerprint', 64);
            $table->unsignedTinyInteger('uploaded');
            $table->unsignedTinyInteger('duplicates');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['upload_session_id', 'upload_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_receipts');
        Schema::table('photos', function (Blueprint $table) {
            $table->dropUnique(['upload_session_id', 'sha256']);
            $table->dropColumn('sha256');
        });
    }
};
