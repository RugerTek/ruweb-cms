<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Business contact data (Settings > Contacto) plus the fields owned by the
 * Header and Footer admin screens. See docs/layout-header-footer.md.
 */
return new class extends Migration
{
    private const ROWS = [
        // Settings > Contacto: the single source for phone, email, address, location and socials.
        ['key' => 'contact_phone', 'value' => null, 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact_whatsapp', 'value' => null, 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact_email', 'value' => null, 'type' => 'string', 'group' => 'contact'],
        ['key' => 'contact_address', 'value' => null, 'type' => 'text', 'group' => 'contact'],
        ['key' => 'contact_location', 'value' => null, 'type' => 'map_point', 'group' => 'contact'],
        ['key' => 'contact_social_links', 'value' => '[]', 'type' => 'social_links', 'group' => 'contact'],
        // Header screen: optional call-to-action button with a semantic destination.
        ['key' => 'header_cta_enabled', 'value' => '0', 'type' => 'boolean', 'group' => 'header'],
        ['key' => 'header_cta_label', 'value' => null, 'type' => 'string', 'group' => 'header'],
        ['key' => 'header_cta_page', 'value' => null, 'type' => 'string', 'group' => 'header'],
        ['key' => 'header_cta_url', 'value' => null, 'type' => 'string', 'group' => 'header'],
        ['key' => 'header_cta_new_tab', 'value' => '0', 'type' => 'boolean', 'group' => 'header'],
        // Footer screen: rich tagline under the logo, copyright line and optional credit.
        ['key' => 'footer_tagline', 'value' => null, 'type' => 'rich_text', 'group' => 'footer'],
        ['key' => 'footer_copyright', 'value' => '© {year} {site}. Todos los derechos reservados.', 'type' => 'string', 'group' => 'footer'],
        ['key' => 'footer_credit_enabled', 'value' => '0', 'type' => 'boolean', 'group' => 'footer'],
        ['key' => 'footer_credit', 'value' => null, 'type' => 'rich_text', 'group' => 'footer'],
    ];

    public function up(): void
    {
        foreach (self::ROWS as $row) {
            DB::table('settings')->updateOrInsert(
                ['key' => $row['key']],
                ['value' => $row['value'], 'type' => $row['type'], 'group' => $row['group'], 'options' => null, 'created_at' => now(), 'updated_at' => now()]
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
