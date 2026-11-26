<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credentials a connector needs to call an external API (the Meta Pixel's Conversions API access token), encrypted
 * with APP_KEY like the Bancard keys of payment_methods, and the site origin they were saved under: a copy restored
 * on another domain, or under another APP_KEY, must ask for them again. See App\Models\Connector::secret().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connectors', function (Blueprint $table) {
            $table->text('secrets')->nullable()->after('token_rotated_at');
            $table->string('bound_origin', 255)->nullable()->after('secrets');
        });
    }

    public function down(): void
    {
        Schema::table('connectors', function (Blueprint $table) {
            $table->dropColumn(['secrets', 'bound_origin']);
        });
    }
};
