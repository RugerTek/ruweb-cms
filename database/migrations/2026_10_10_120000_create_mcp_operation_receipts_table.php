<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_operation_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 80);
            $table->string('operation_key', 100);
            $table->char('payload_hash', 64);
            $table->string('model_type');
            $table->unsignedBigInteger('model_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'operation', 'operation_key'], 'mcp_receipt_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_operation_receipts');
    }
};
