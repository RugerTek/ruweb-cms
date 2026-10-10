<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable();
            $table->string('pending_email_token', 64)->nullable();
            $table->timestamp('pending_email_expires_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_counter')->nullable();
            $table->unsignedInteger('security_version')->default(0);
        });
        Schema::create('admin_sessions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_active_at');
            $table->timestamp('revoked_at')->nullable();
        });
        Schema::create('mcp_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key_hash', 64)->unique();
            $table->string('scope')->default('full');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        DB::table('users')->whereNotNull('mcp_api_key_hash')->orderBy('id')->each(function ($user) {
            DB::table('mcp_api_keys')->insert([
                'user_id' => $user->id, 'name' => 'Legacy', 'key_hash' => $user->mcp_api_key_hash,
                'scope' => 'full', 'last_used_at' => $user->mcp_api_key_last_used_at,
                'created_at' => $user->mcp_api_key_generated_at ?? now(), 'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_api_keys');
        Schema::dropIfExists('admin_sessions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'pending_email', 'pending_email_token', 'pending_email_expires_at', 'two_factor_secret',
            'two_factor_recovery_codes', 'two_factor_confirmed_at', 'two_factor_last_counter', 'security_version',
        ]));
    }
};
