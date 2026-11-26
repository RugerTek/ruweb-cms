<?php

use App\Models\Setting;
use App\Support\SettingDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admin > Email can send through any SMTP server besides Brevo (docs/operations/email.md). The provider starts as
 * `brevo`, so a site that already sends with its Brevo key keeps doing so; the SMTP fields start empty (port 587,
 * TLS). Rows that already exist are left as they are.
 */
return new class extends Migration
{
    private const SETTINGS = ['mail_provider', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password'];

    public function up(): void
    {
        foreach (self::SETTINGS as $key) {
            if (DB::table('settings')->where('key', $key)->exists()) {
                continue;
            }
            $setting = SettingDefaults::for($key);
            DB::table('settings')->insert([
                'key' => $key,
                'value' => $setting['value'],
                'type' => $setting['type'],
                'group' => $setting['group'],
                'options' => $setting['options'] === null ? null : json_encode($setting['options']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', self::SETTINGS)->delete();
        Setting::clearCache();
    }
};
