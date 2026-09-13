<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Single read path for the business contact data stored in Settings > Contacto.
 *
 * Header, footer, contact pages and any future template read phone, email,
 * address, coordinates and social links from here instead of resolving
 * settings inline, so the values stay consistent everywhere and the href
 * derivations (tel:, mailto:, wa.me, maps) live in one place.
 * See docs/admin/layout.md.
 */
class BusinessContact
{
    public static function phone(): ?string
    {
        return self::string('contact_phone');
    }

    /** `tel:` href built from the phone number, keeping only digits and a leading plus. */
    public static function phoneHref(): ?string
    {
        $phone = self::phone();

        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9+]/', '', $phone) ?? '';

        return $digits !== '' ? 'tel:'.$digits : null;
    }

    /** WhatsApp number as stored (digits with country code, no plus). */
    public static function whatsapp(): ?string
    {
        return self::string('contact_whatsapp');
    }

    public static function whatsappHref(): ?string
    {
        $number = self::whatsapp();

        if ($number === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $number) ?? '';

        return $digits !== '' ? 'https://wa.me/'.$digits : null;
    }

    public static function email(): ?string
    {
        return self::string('contact_email');
    }

    public static function emailHref(): ?string
    {
        $email = self::email();

        return $email !== null ? 'mailto:'.$email : null;
    }

    /** Human-readable address, possibly multi-line. */
    public static function address(): ?string
    {
        return self::string('contact_address');
    }

    /** @return array{lat: float, lng: float}|null */
    public static function coordinates(): ?array
    {
        $point = Setting::get('contact_location');

        return is_array($point) ? $point : null;
    }

    /**
     * Link to open the location in a maps app: coordinates when present,
     * otherwise a search for the written address.
     */
    public static function mapsHref(): ?string
    {
        if ($coordinates = self::coordinates()) {
            return sprintf('https://www.google.com/maps/search/?api=1&query=%s,%s', $coordinates['lat'], $coordinates['lng']);
        }

        $address = self::address();

        if ($address === null) {
            return null;
        }

        return 'https://www.google.com/maps/search/?api=1&query='.urlencode(preg_replace('/\s+/', ' ', $address));
    }

    /**
     * Configured social links, in the order the client set them, skipping
     * rows whose network is unknown or whose URL is empty.
     *
     * @return list<array{network: string, label: string, url: string, icon: string}>
     */
    public static function socialLinks(): array
    {
        $rows = Setting::get('contact_social_links');

        if (! is_array($rows)) {
            return [];
        }

        $links = [];

        foreach ($rows as $row) {
            $network = is_array($row) ? (string) ($row['network'] ?? '') : '';
            $url = is_array($row) ? trim((string) ($row['url'] ?? '')) : '';

            if ($url === '' || ! SocialNetworks::has($network)) {
                continue;
            }

            $links[] = [
                'network' => $network,
                'label' => SocialNetworks::label($network),
                'url' => $url,
                'icon' => (string) SocialNetworks::iconPath($network),
            ];
        }

        return $links;
    }

    /** True when at least one piece of contact data is configured. */
    public static function hasAny(): bool
    {
        return self::phone() !== null
            || self::whatsapp() !== null
            || self::email() !== null
            || self::address() !== null;
    }

    private static function string(string $key): ?string
    {
        $value = Setting::get($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
