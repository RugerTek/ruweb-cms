<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'site_name',              'value' => 'Rüweb', 'type' => 'string', 'group' => 'general', 'options' => null],
            ['key' => 'site_description',       'value' => 'Un CMS ligero y extensible para comenzar rápido.', 'type' => 'text',   'group' => 'general', 'options' => null],
            ['key' => 'site_logo',              'value' => null,               'type' => 'media',  'group' => 'general', 'options' => null],
            ['key' => 'site_favicon',           'value' => null,               'type' => 'media',  'group' => 'general', 'options' => null],
            ['key' => 'default_social_image',   'value' => null,               'type' => 'media',  'group' => 'general', 'options' => null],
            ['key' => 'homepage_translation_group_id', 'value' => null,         'type' => 'select', 'group' => 'general', 'options' => []],
            ['key' => 'admin_locale',           'value' => 'es',               'type' => 'select', 'group' => 'admin',   'options' => ['es' => 'Español', 'en' => 'English']],
            ['key' => 'admin_login_path',       'value' => 'login',            'type' => 'string', 'group' => 'admin',   'options' => null],
            ['key' => 'mail_enabled',           'value' => '0',                'type' => 'boolean', 'group' => 'email',     'options' => null],
            ['key' => 'brevo_api_key',          'value' => null,               'type' => 'password', 'group' => 'email',    'options' => null],
            ['key' => 'mail_from_address',      'value' => null,               'type' => 'string', 'group' => 'email',   'options' => null],
            ['key' => 'mail_from_name',         'value' => null,               'type' => 'string', 'group' => 'email',   'options' => null],
            ['key' => 'recaptcha_site_key',     'value' => null,               'type' => 'string', 'group' => 'security', 'options' => null],
            ['key' => 'recaptcha_secret_key',   'value' => null,               'type' => 'password', 'group' => 'security', 'options' => null],
            ['key' => 'recaptcha_min_score',    'value' => '0.5',              'type' => 'string', 'group' => 'security', 'options' => null],
            ['key' => 'google_tag_id',          'value' => null,               'type' => 'string', 'group' => 'analytics', 'options' => null],
            ['key' => 'meta_pixel_id',          'value' => null,               'type' => 'string', 'group' => 'analytics', 'options' => null],
            ['key' => 'search_console_verification_token', 'value' => null,     'type' => 'string', 'group' => 'analytics', 'options' => null],
        ];

        foreach ($settings as $setting) {
            Setting::updateOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }

        Setting::clearCache();
    }
}
