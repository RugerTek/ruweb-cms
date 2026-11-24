<?php

namespace App\Services\Marketing;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * The `user_data` of a Conversions API event (Meta, "Customer Information Parameters", updated 2026-01-09, consulted
 * 2026-11-24). Contact details are normalized as Meta asks and hashed with SHA-256 here, before the event is queued:
 * em (trimmed, lowercase), ph (digits with the country calling code, no leading zeros), fn/ln and ct (lowercase, no
 * punctuation or spaces), country (ISO 3166-1 alpha-2, lowercase). They exist for an order (Purchase) and for the
 * contact form (Lead: email, phone and name typed by the visitor); every event carries what the browser sends
 * anyway: client_ip_address and client_user_agent (never hashed) and the `_fbp` and `_fbc` cookies of the Meta Pixel
 * (fbc is built from `fbclid` when the cookie does not exist yet, once per request).
 */
final class MetaPixelUserData
{
    /** `fb.{subdomain index}.{creation ms}.{random or click id}`, the shape of the cookies the pixel writes. */
    private const COOKIE_PATTERN = '/^fb\.\d\.\d{10,16}\.[A-Za-z0-9_\-.]{1,500}$/';

    /** Request attribute holding the fbp/fbc worked out for the request (browserIds()). */
    private const BROWSER_IDS_ATTRIBUTE = 'meta_pixel.browser_ids';

    /**
     * What the current visitor's request tells: IP, user agent, fbp and fbc.
     *
     * @return array<string, string>
     */
    public static function fromRequest(Request $request): array
    {
        return array_filter([
            'client_ip_address' => (string) $request->ip(),
            'client_user_agent' => (string) $request->userAgent(),
            ...self::browserIds($request),
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * The `_fbp` and `_fbc` cookies (unencrypted: App\Providers\MetaPixelServiceProvider leaves them out of
     * EncryptCookies) and, when there is no `_fbc` for the `fbclid` of the URL, a click ID built from it.
     *
     * Worked out once per request and kept in its attributes, so every event of the request (PageView, ViewContent…)
     * carries the same fbc (Meta, "ClickID and the fbp and fbc Parameters", updated 2026-01-09, consulted 2026-11-24):
     * - a valid `_fbc` cookie is sent as it is (Meta's script wrote it, with its own subdomain index);
     * - without it, or when the URL brings a new `fbclid` (a new ad click; the cookie's click ID is the part after
     *   its last "."), `fb.1.{ms when first seen}.{fbclid}`: subdomain index 1 is what Meta asks for a value generated
     *   on the server, and the click ID is kept as received (case sensitive).
     *
     * @return array{fbp?: string, fbc?: string}
     */
    public static function browserIds(Request $request): array
    {
        $memo = $request->attributes->get(self::BROWSER_IDS_ATTRIBUTE);
        if (is_array($memo)) {
            return $memo;
        }

        $ids = [];
        $fbp = $request->cookies->get('_fbp');
        if (is_string($fbp) && preg_match(self::COOKIE_PATTERN, $fbp) === 1) {
            $ids['fbp'] = $fbp;
        }
        $fbc = $request->cookies->get('_fbc');
        $fbc = is_string($fbc) && preg_match(self::COOKIE_PATTERN, $fbc) === 1 ? $fbc : null;
        $clickId = $request->query('fbclid');
        $clickId = is_string($clickId) && preg_match('/^[A-Za-z0-9_\-]{1,500}$/', $clickId) === 1 ? $clickId : null;
        if ($fbc !== null && ($clickId === null || substr($fbc, strrpos($fbc, '.') + 1) === $clickId)) {
            $ids['fbc'] = $fbc;
        } elseif ($clickId !== null) {
            $ids['fbc'] = 'fb.1.'.(int) floor(microtime(true) * 1000).'.'.$clickId;
        }
        $request->attributes->set(self::BROWSER_IDS_ATTRIBUTE, $ids);

        return $ids;
    }

    /**
     * The contact details a visitor typed in a form (the contact form's Lead), normalized and hashed like an order's:
     * em, ph and, when a name was given, fn/ln. Nothing else of the form (subject, message) goes to Meta.
     *
     * @return array<string, string>
     */
    public static function fromContact(?string $name, ?string $email, ?string $phone): array
    {
        [$first, $last] = self::splitName((string) $name);

        return array_filter([
            'em' => self::hash(mb_strtolower(trim((string) $email))),
            'ph' => self::hash(self::phone((string) $phone)),
            'fn' => self::hash(self::letters($first)),
            'ln' => self::hash(self::letters($last)),
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * The customer of an order, hashed, plus the IP and user agent the order was placed from. $browser adds the fbp
     * and fbc kept when the order was placed (MetaPixel::rememberOrderContext()).
     *
     * @param  array{fbp?: string, fbc?: string}  $browser
     * @return array<string, string>
     */
    public static function fromOrder(Order $order, array $browser = []): array
    {
        [$first, $last] = self::splitName((string) $order->customer_name);
        $city = $order->shippingAddress?->city;

        return array_filter([
            'em' => self::hash(mb_strtolower(trim((string) $order->customer_email))),
            'ph' => self::hash(self::phone((string) $order->customer_phone)),
            'fn' => self::hash(self::letters($first)),
            'ln' => self::hash(self::letters($last)),
            'ct' => self::hash(self::letters((string) $city)),
            'country' => self::hash(mb_strtolower((string) config('cms.meta_pixel.country', ''))),
            'client_ip_address' => (string) $order->ip,
            'client_user_agent' => (string) $order->user_agent,
            'fbp' => (string) ($browser['fbp'] ?? ''),
            'fbc' => (string) ($browser['fbc'] ?? ''),
        ], fn (string $value): bool => $value !== '');
    }

    /** Digits with the country calling code: `+595 981 123-456`, `00595…` and `0981 123456` → `595981123456`. */
    public static function phone(string $phone): string
    {
        $trimmed = trim($phone);
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($trimmed, '+')) {
            return ltrim($digits, '0');
        }
        if (str_starts_with($digits, '00')) {
            return ltrim($digits, '0');
        }
        $digits = ltrim($digits, '0');
        $code = (string) config('cms.meta_pixel.phone_country_code', '');

        return $code !== '' && ! str_starts_with($digits, $code) ? $code.$digits : $digits;
    }

    /** Lowercase letters only (accents kept, UTF-8), as Meta normalizes names and cities. */
    public static function letters(string $value): string
    {
        return preg_replace('/[^\p{L}]+/u', '', mb_strtolower(trim($value))) ?? '';
    }

    /** SHA-256 of an already normalized value; '' stays '' (left out). */
    public static function hash(string $normalized): string
    {
        return $normalized === '' ? '' : hash('sha256', $normalized);
    }

    /** @return array{0: string, 1: string} first word and the rest */
    private static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];

        return [$parts[0] ?? '', $parts[1] ?? ''];
    }
}
