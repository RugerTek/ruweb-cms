<?php

namespace App\Http\Controllers\Admin\Concerns;

use App\Services\Settings\SettingOperations;
use App\Support\LinkDestination;
use App\Support\RichTextSanitizer;
use App\Support\SiteUrl;
use App\Support\SocialNetworks;
use Illuminate\Validation\Rule;

/**
 * Shared save path for the screens that edit settings rows: the Settings
 * tabs plus the dedicated Header and Footer screens (docs/admin/settings.md,
 * docs/admin/layout.md).
 */
trait PersistsSettings
{
    /**
     * Write a `settings[key] => value` payload, keeping stored passwords when
     * the field comes back blank and normalising typed values.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function persistSettings(array $settings): void
    {
        app(SettingOperations::class)->persist(request()->user(), $settings);
    }

    /**
     * Validation rules for the business contact group (Settings > Contacto).
     *
     * @return array<string, array<int, mixed>>
     */
    protected function contactSettingRules(): array
    {
        return [
            'settings.contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'settings.contact_whatsapp' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s().-]{6,20}$/'],
            'settings.contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'settings.contact_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.contact_location' => ['sometimes', 'nullable', 'array'],
            'settings.contact_location.lat' => ['nullable', 'required_with:settings.contact_location.lng', 'numeric', 'between:-90,90'],
            'settings.contact_location.lng' => ['nullable', 'required_with:settings.contact_location.lat', 'numeric', 'between:-180,180'],
            'settings.contact_social_links' => ['sometimes', 'nullable', 'array', 'max:20'],
            'settings.contact_social_links.*.network' => ['required_with:settings.contact_social_links.*.url', 'string', Rule::in(SocialNetworks::keys())],
            'settings.contact_social_links.*.url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ];
    }

    /**
     * Validation rules for the fields owned by the Header screen.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function headerSettingRules(): array
    {
        return [
            'settings.header_cta_enabled' => ['sometimes', 'boolean'],
            'settings.header_cta_label' => ['sometimes', 'nullable', 'string', 'max:60', 'required_if:settings.header_cta_enabled,1'],
            'settings.header_cta_page' => ['sometimes', 'nullable', 'string', 'max:64', Rule::in([LinkDestination::CUSTOM, ...array_keys(LinkDestination::pageOptions())])],
            'settings.header_cta_url' => ['sometimes', 'nullable', 'string', 'max:2048', 'regex:'.LinkDestination::CUSTOM_URL_PATTERN],
            'settings.header_cta_new_tab' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Validation rules for the fields owned by the Footer screen.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function footerSettingRules(): array
    {
        return [
            'settings.footer_tagline' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'settings.footer_copyright' => ['sometimes', 'nullable', 'string', 'max:200'],
            'settings.footer_credit_enabled' => ['sometimes', 'boolean'],
            'settings.footer_credit' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * A destination selector submits both the page reference and the custom
     * URI; only the selected mode is stored (docs/admin/live-editor-ui-ux.md, rule 11).
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    protected function normalizeLinkDestination(array $settings, string $pageKey, string $urlKey): array
    {
        if (! array_key_exists($pageKey, $settings) && ! array_key_exists($urlKey, $settings)) {
            return $settings;
        }

        $page = trim((string) ($settings[$pageKey] ?? ''));

        if ($page === '' || $page === LinkDestination::CUSTOM) {
            $settings[$pageKey] = null;
            $settings[$urlKey] = SiteUrl::toRelative(trim((string) ($settings[$urlKey] ?? ''))) ?: null;
        } else {
            $settings[$urlKey] = null;
        }

        return $settings;
    }

    /**
     * Keep only rows with a known network and a non-empty URL, in the order
     * the client left them.
     *
     * @return list<array{network: string, url: string}>
     */
    private function normalizeSocialLinks(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $network = trim((string) ($row['network'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));

            if ($url === '' || ! SocialNetworks::has($network)) {
                continue;
            }

            $normalized[] = ['network' => $network, 'url' => $url];
        }

        return $normalized;
    }

    /** @return array{lat: float, lng: float}|null */
    private function normalizeMapPoint(mixed $point): ?array
    {
        if (! is_array($point)) {
            return null;
        }

        $lat = trim((string) ($point['lat'] ?? ''));
        $lng = trim((string) ($point['lng'] ?? ''));

        if ($lat === '' || $lng === '' || ! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    /** Rich text is sanitised on save so every reader gets the same markup (docs/admin/rich-text.md). */
    private function normalizeRichText(mixed $html): ?string
    {
        $clean = app(RichTextSanitizer::class)->sanitize(is_string($html) ? $html : null);

        if ($clean === null) {
            return null;
        }

        // An "empty" editor still submits a blank paragraph, often with &nbsp;.
        $text = preg_replace('/[\s\x{00A0}]+/u', '', html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '';

        return $text === '' ? null : $clean;
    }
}
