<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('can_manage_custom_code')->default(false));
        Schema::table('oauth_authorization_codes', fn (Blueprint $table) => $table->string('scope')->default('full'));
        Schema::table('oauth_access_tokens', fn (Blueprint $table) => $table->string('scope')->default('full'));
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('can_manage_custom_code'));
        Schema::table('oauth_authorization_codes', fn (Blueprint $table) => $table->dropColumn('scope'));
        Schema::table('oauth_access_tokens', fn (Blueprint $table) => $table->dropColumn('scope'));
    }
};
