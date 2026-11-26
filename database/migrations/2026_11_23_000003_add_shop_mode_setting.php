<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The shop module switch (Settings > Shop). Starts off; existing installs keep a row they already have.
 * See docs/shop/catalog.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('settings')->where('key', 'shop_mode')->exists()) {
            DB::table('settings')->insert([
                'key' => 'shop_mode',
                'value' => 'off',
                'type' => 'select',
                'group' => 'shop',
                'options' => json_encode(['off' => 'off', 'catalog' => 'catalog', 'checkout' => 'checkout']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'shop_mode')->delete();
        Setting::clearCache();
    }
};
