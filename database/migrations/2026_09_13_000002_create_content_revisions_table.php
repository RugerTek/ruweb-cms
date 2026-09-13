<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_revisions', function (Blueprint $table) {
            $table->id();
            $table->char('hash', 64)->unique();
            $table->char('parent_hash', 64)->nullable()->index();
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 20);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_revisions');
    }
};
