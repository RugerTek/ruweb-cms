<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->uuid('notification_claim')->nullable();
        });
        Schema::create('submission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('form', 100);
            $table->unsignedBigInteger('submission_id');
            $table->string('action', 40);
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at');
            $table->index(['form', 'submission_id']);
        });
        Schema::create('submission_operation_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('operation_key', 100);
            $table->char('payload_hash', 64);
            $table->json('result');
            $table->timestamps();
            $table->unique(['user_id', 'action', 'operation_key'], 'submission_receipt_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_operation_receipts');
        Schema::dropIfExists('submission_events');
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropColumn('notification_claim');
        });
    }
};
