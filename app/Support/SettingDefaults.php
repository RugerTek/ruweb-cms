<?php

namespace App\Support;

/**
 * The starter value of every setting: the seeder installs them and settings_reset restores them, so a default is
 * defined once. Changing a default here changes both.
 */
final class SettingDefaults
{
    /** @return array<int, array{key: string, value: mixed, type: string, group: string, options: mixed}> */
    public static function all(): array
    {
        return [
            ['key' => 'site_name',              'value' => 'Rüweb', 'type' => 'string', 'group' => 'general', 'options' => null],
            ['key' => 'site_description',       'value' => 'Un CMS ligero y extensible para comenzar rápido.', 'type' => 'text',   'group' => 'general', 'options' => null],
            ['key' => 'site_logo',              'value' => null,               'type' => 'media',  'group' => 'general', 'options' => null],
            ['key' => 'site_favicon',           'value' => null,               'type' => 'media',  'group' => 'general', 'options' => null],
            ['key' => 'default_social_image',   'value' => null,               'type' => 'media',  'group' => 'general', 'options' => null],
            ['key' => 'homepage_translation_group_id', 'value' => null,         'type' => 'select', 'group' => 'general', 'options' => []],
            ['key' => 'admin_locale',           'value' => 'es',               'type' => 'select', 'group' => 'admin',   'options' => ['es' => 'Español', 'en' => 'English']],
            ['key' => 'admin_login_path',       'value' => 'login',            'type' => 'string', 'group' => 'admin',   'options' => null],
            ['key' => 'mail_enabled',           'value' => '0',                'type' => 'boolean', 'group' => 'email',     'options' => null],
            // Admin > Email (docs/operations/email.md): Brevo by API key or any SMTP server; secrets are stored encrypted.
            ['key' => 'mail_provider',          'value' => 'brevo',            'type' => 'select', 'group' => 'email',   'options' => ['brevo' => 'Brevo', 'smtp' => 'SMTP']],
            ['key' => 'brevo_api_key',          'value' => null,               'type' => 'password', 'group' => 'email',    'options' => null],
            ['key' => 'smtp_host',              'value' => null,               'type' => 'string', 'group' => 'email',   'options' => null],
            ['key' => 'smtp_port',              'value' => '587',              'type' => 'integer', 'group' => 'email',  'options' => null],
            ['key' => 'smtp_encryption',        'value' => 'tls',              'type' => 'select', 'group' => 'email',   'options' => ['tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'none']],
            ['key' => 'smtp_username',          'value' => null,               'type' => 'string', 'group' => 'email',   'options' => null],
            ['key' => 'smtp_password',          'value' => null,               'type' => 'password', 'group' => 'email',    'options' => null],
            ['key' => 'mail_from_address',      'value' => null,               'type' => 'string', 'group' => 'email',   'options' => null],
            ['key' => 'mail_from_name',         'value' => null,               'type' => 'string', 'group' => 'email',   'options' => null],
            ['key' => 'recaptcha_site_key',     'value' => null,               'type' => 'string', 'group' => 'security', 'options' => null],
            ['key' => 'recaptcha_secret_key',   'value' => null,               'type' => 'password', 'group' => 'security', 'options' => null],
            ['key' => 'recaptcha_min_score',    'value' => '0.5',              'type' => 'string', 'group' => 'security', 'options' => null],
            // Business contact data, read everywhere through App\Support\BusinessContact.
            ['key' => 'contact_phone',          'value' => null,               'type' => 'string', 'group' => 'contact',  'options' => null],
            ['key' => 'contact_whatsapp',       'value' => null,               'type' => 'string', 'group' => 'contact',  'options' => null],
            ['key' => 'contact_email',          'value' => null,               'type' => 'string', 'group' => 'contact',  'options' => null],
            ['key' => 'contact_address',        'value' => null,               'type' => 'text',   'group' => 'contact',  'options' => null],
            ['key' => 'contact_location',       'value' => null,               'type' => 'map_point', 'group' => 'contact', 'options' => null],
            ['key' => 'contact_social_links',   'value' => '[]',               'type' => 'social_links', 'group' => 'contact', 'options' => null],
            // Header and footer screens (docs/admin/layout.md).
            ['key' => 'header_cta_enabled',     'value' => '0',                'type' => 'boolean', 'group' => 'header',  'options' => null],
            ['key' => 'header_cta_label',       'value' => null,               'type' => 'string', 'group' => 'header',   'options' => null],
            ['key' => 'header_cta_page',        'value' => null,               'type' => 'string', 'group' => 'header',   'options' => null],
            ['key' => 'header_cta_url',         'value' => null,               'type' => 'string', 'group' => 'header',   'options' => null],
            ['key' => 'header_cta_new_tab',     'value' => '0',                'type' => 'boolean', 'group' => 'header',  'options' => null],
            ['key' => 'footer_tagline',         'value' => null,               'type' => 'rich_text', 'group' => 'footer', 'options' => null],
            ['key' => 'footer_copyright',       'value' => '© {year} {site}. Todos los derechos reservados.', 'type' => 'string', 'group' => 'footer', 'options' => null],
            ['key' => 'footer_credit_enabled',  'value' => '0',                'type' => 'boolean', 'group' => 'footer',  'options' => null],
            ['key' => 'footer_credit',          'value' => null,               'type' => 'rich_text', 'group' => 'footer', 'options' => null],
            ['key' => 'google_tag_id',          'value' => null,               'type' => 'string', 'group' => 'analytics', 'options' => null],
            ['key' => 'meta_pixel_id',          'value' => null,               'type' => 'string', 'group' => 'analytics', 'options' => null],
            ['key' => 'search_console_verification_token', 'value' => null,     'type' => 'string', 'group' => 'analytics', 'options' => null],
            // Shop module switch, edited only through App\Services\Shop\ShopModuleOperations (docs/shop/catalog.md).
            ['key' => 'shop_mode',              'value' => 'off',              'type' => 'select', 'group' => 'shop',      'options' => ['off' => 'off', 'catalog' => 'catalog', 'checkout' => 'checkout']],
            // Selling online (PLAN.md E3.3, E3.4): order notices go here (empty = contact_email), store pickup,
            // and the page with the purchase policies the checkout asks to accept.
            ['key' => 'shop_orders_email',      'value' => null,               'type' => 'string', 'group' => 'shop',      'options' => null],
            ['key' => 'shop_pickup_enabled',    'value' => '0',                'type' => 'boolean', 'group' => 'shop',     'options' => null],
            ['key' => 'shop_pickup_address',    'value' => null,               'type' => 'text',   'group' => 'shop',      'options' => null],
            ['key' => 'shop_terms_page_id',     'value' => null,               'type' => 'string', 'group' => 'shop',      'options' => null],
        ];
    }

    public static function for(string $key): ?array
    {
        foreach (self::all() as $setting) {
            if ($setting['key'] === $key) {
                return $setting;
            }
        }

        return null;
    }
}
