<?php

use App\Models\Setting;
use App\Support\SiteTime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installs keep their stored dates (UTC); only the zone they are shown in is new.
        if (! DB::table('settings')->where('key', 'site_timezone')->exists()) {
            DB::table('settings')->insert([
                'key' => 'site_timezone',
                'value' => SiteTime::configured(),
                'type' => 'select',
                'group' => 'general',
                'options' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'site_timezone')->delete();

        Setting::clearCache();
    }
};
