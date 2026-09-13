<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ROWS = [
        ['key' => 'recaptcha_site_key', 'value' => null, 'type' => 'string'],
        ['key' => 'recaptcha_secret_key', 'value' => null, 'type' => 'password'],
        ['key' => 'recaptcha_min_score', 'value' => '0.5', 'type' => 'string'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as $row) {
            DB::table('settings')->updateOrInsert(
                ['key' => $row['key']],
                ['value' => $row['value'], 'type' => $row['type'], 'group' => 'security', 'options' => null, 'created_at' => now(), 'updated_at' => now()]
            );
        }

        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', array_column(self::ROWS, 'key'))->delete();
        Setting::clearCache();
    }
};
