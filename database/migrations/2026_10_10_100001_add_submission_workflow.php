<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->string('workflow_status', 20)->default('new')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notification_status', 20)->default('skipped')->index();
            $table->string('notification_token', 36)->nullable();
            $table->string('notification_email', 190)->nullable();
            $table->unsignedInteger('notification_attempts')->default(0);
            $table->text('notification_error')->nullable();
            $table->timestamp('notification_started_at')->nullable();
            $table->timestamp('notification_failed_at')->nullable();
        });
        DB::table('contact_submissions')->whereNotNull('notified_at')->update(['notification_status' => 'sent']);
        Schema::create('contact_submission_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submission_notes');
        Schema::table('contact_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['workflow_status', 'notification_status', 'notification_token', 'notification_email', 'notification_attempts', 'notification_error', 'notification_started_at', 'notification_failed_at']);
        });
    }
};
