<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support access v2 (docs/operations/support-access.md): every pass carries a reason (and optionally a ticket), kept
 * with the session the client sees; each site can turn support access off (`support_access_changes`, one row per
 * change: who and when; no row means allowed); each client administrator can dismiss the panel notice of recent
 * support entries (`support_notice_dismissals`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_sessions', function (Blueprint $table) {
            // Nullable only for sessions opened before v2; every new pass must carry one.
            $table->string('reason', 500)->nullable();
            $table->string('ticket', 64)->nullable();
        });

        Schema::create('support_access_changes', function (Blueprint $table) {
            $table->id();
            $table->boolean('allowed');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name', 255);
            $table->string('ip', 45)->nullable();
            $table->timestamp('changed_at')->index();
        });

        Schema::create('support_notice_dismissals', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->timestamp('dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_notice_dismissals');
        Schema::dropIfExists('support_access_changes');
        Schema::table('support_sessions', function (Blueprint $table) {
            $table->dropColumn(['reason', 'ticket']);
        });
    }
};
