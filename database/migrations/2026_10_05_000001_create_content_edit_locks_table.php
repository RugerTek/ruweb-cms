<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_edit_locks', function (Blueprint $table) {
            $table->string('content_type', 100);
            $table->unsignedBigInteger('content_id');
            $table->unsignedBigInteger('user_id');
            $table->string('user_name');
            $table->string('session_hash', 64);
            $table->uuid('token');
            $table->timestamp('expires_at');
            $table->primary(['content_type', 'content_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_edit_locks');
    }
};
