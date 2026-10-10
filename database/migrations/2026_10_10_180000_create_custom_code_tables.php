<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('custom_code_snippets', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->text('description')->nullable();
            $table->string('state')->default('draft'); $table->unsignedBigInteger('active_version_id')->nullable();
            $table->unsignedInteger('generation')->default(0); $table->timestamps();
        });
        Schema::create('custom_code_versions', function (Blueprint $table) {
            $table->id(); $table->foreignId('snippet_id')->constrained('custom_code_snippets')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload'); $table->string('fingerprint', 64); $table->timestamp('created_at');
        });
        Schema::create('custom_code_events', function (Blueprint $table) {
            $table->id(); $table->foreignId('snippet_id')->constrained('custom_code_snippets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action'); $table->unsignedBigInteger('version_id')->nullable(); $table->json('detail'); $table->timestamp('created_at');
        });
        Schema::create('custom_code_receipts', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('operation_key', 100); $table->string('payload_hash', 64); $table->json('result'); $table->timestamp('created_at');
            $table->unique(['user_id', 'operation_key']);
        });
    }
    public function down(): void
    {
        foreach (['custom_code_receipts', 'custom_code_events', 'custom_code_versions', 'custom_code_snippets'] as $table) Schema::dropIfExists($table);
    }
};
