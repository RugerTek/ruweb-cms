<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Acceso de soporte RügerTek» (docs/operations/support-access.md): RügerTek operators enter the admin with a pass
 * signed by the RuWeb Cloud console. `users.is_support` marks the per-operator account; `support_sessions` records who
 * entered and until when; `support_audit` what they did (field NAMES only, never values); `support_pass_jtis` makes
 * every pass single-use until it expires. Always created: a site without the public key keeps them empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_support')->default(false)->index();
        });

        Schema::create('support_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operator_email', 191);
            $table->string('operator_name', 120);
            $table->string('role', 20);
            $table->string('issuer', 100);
            $table->string('jti', 128)->unique();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 20)->nullable();
        });

        Schema::create('support_audit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('support_sessions')->cascadeOnDelete();
            $table->timestamp('at')->index();
            $table->string('method', 10);
            $table->string('route_name', 191)->nullable();
            $table->string('path', 500);
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('subject_label', 191)->nullable();
            $table->json('changed_fields')->nullable();
            $table->unsignedSmallInteger('status')->nullable();
        });

        Schema::create('support_pass_jtis', function (Blueprint $table) {
            $table->string('jti', 128)->primary();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_pass_jtis');
        Schema::dropIfExists('support_audit');
        Schema::dropIfExists('support_sessions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_support']);
            $table->dropColumn('is_support');
        });
    }
};
