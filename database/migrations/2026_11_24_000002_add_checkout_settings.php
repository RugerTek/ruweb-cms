<?php

use App\Models\Setting;
use App\Support\SettingDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settings for selling online (PLAN.md E3.3) and the three payment methods every site can offer (E3.4): bank
 * transfer and cash on delivery (provider `manual`) and Bancard. They start disabled and empty: their data belongs
 * to the site and is filled from the panel, never invented here. Rows that already exist are left as they are.
 */
return new class extends Migration
{
    private const SETTINGS = ['shop_orders_email', 'shop_pickup_enabled', 'shop_pickup_address', 'shop_terms_page_id'];

    private const PAYMENT_METHODS = [
        ['key' => 'bank_transfer', 'provider' => 'manual', 'sort_order' => 1, 'environment' => null],
        ['key' => 'cod', 'provider' => 'manual', 'sort_order' => 2, 'environment' => null],
        ['key' => 'bancard', 'provider' => 'bancard', 'sort_order' => 3, 'environment' => 'staging'],
    ];

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

        foreach (self::PAYMENT_METHODS as $method) {
            if (DB::table('payment_methods')->where('key', $method['key'])->exists()) {
                continue;
            }
            DB::table('payment_methods')->insert($method + [
                'enabled' => false,
                'settings_json' => null,
                'secrets' => null,
                'bound_origin' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Setting::clearCache();
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', self::SETTINGS)->delete();
        DB::table('payment_methods')->whereIn('key', array_column(self::PAYMENT_METHODS, 'key'))->delete();
        Setting::clearCache();
    }
};
