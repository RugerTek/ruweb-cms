<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_versions', function (Blueprint $table) {
            $table->string('base_fingerprint', 64)->nullable();
        });
        Schema::create('editorial_operation_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('content_type', 32);
            $table->unsignedBigInteger('content_id');
            $table->string('operation_key', 128);
            $table->string('request_hash', 64);
            $table->json('result');
            $table->timestamps();
            $table->unique(['user_id', 'content_type', 'content_id', 'operation_key'], 'editorial_receipt_operation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_operation_receipts');
        Schema::table('editorial_versions', function (Blueprint $table) {
            $table->dropColumn('base_fingerprint');
        });
    }
};
