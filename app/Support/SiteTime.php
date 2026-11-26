<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Dates are stored in UTC (config/app.php `timezone`) and shown in the site's time zone: the `site_timezone`
 * setting (Settings > General), seeded from `cms.display_timezone` (APP_DISPLAY_TIMEZONE). Every date a person reads
 * (panel, public site, emails, exports) goes through display helpers here, and every date a person types in a form
 * goes through toStorage(). See docs/concepts/time-zones.md.
 */
final class SiteTime
{
    public const DEFAULT_TIMEZONE = 'America/Asuncion';

    /** The zone dates are shown in. Falls back to the configured initial value when the setting is missing or invalid. */
    public static function timezone(): string
    {
        try {
            $value = Setting::get('site_timezone');
        } catch (Throwable) {
            // Before migrations run (installer, early boot) there is no settings table.
            $value = null;
        }

        if (is_string($value) && self::isValid($value)) {
            return $value;
        }

        return self::configured();
    }

    /** Initial value for the setting: APP_DISPLAY_TIMEZONE through config, or the starter default. */
    public static function configured(): string
    {
        $value = config('cms.display_timezone');

        return is_string($value) && self::isValid($value) ? $value : self::DEFAULT_TIMEZONE;
    }

    public static function isValid(string $timezone): bool
    {
        return isset(self::options()[$timezone]) || $timezone === 'UTC';
    }

    /** @return array<string, string> identifier => label, for the Settings select. */
    public static function options(): array
    {
        // Built once per process: timezone() runs for every date shown on a page.
        static $options = null;

        if ($options === null) {
            $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
            $options = array_combine($identifiers, $identifiers);
        }

        return $options;
    }

    /**
     * A stored date (UTC) moved to the site zone; null stays null. Strings without offset are read as UTC. A string
     * that is not a date (e.g. from an uploaded snapshot manifest) gives null, so views show their fallback.
     */
    public static function local(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $date = $value instanceof DateTimeInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse($value, 'UTC');
        } catch (Throwable) {
            return null;
        }

        return $date->setTimezone(self::timezone());
    }

    /** Format a stored date for people. Returns $fallback when there is no date. */
    public static function format(DateTimeInterface|string|null $value, string $format = 'd/m/Y H:i', string $fallback = ''): string
    {
        return self::local($value)?->format($format) ?? $fallback;
    }

    /** Localized format (month names) for public pages, in the site zone. */
    public static function translatedFormat(DateTimeInterface|string|null $value, string $format, string $fallback = ''): string
    {
        return self::local($value)?->locale(app()->getLocale())->translatedFormat($format) ?? $fallback;
    }

    /** Value for an <input type="datetime-local">: the stored date in the site zone. */
    public static function input(DateTimeInterface|string|null $value): string
    {
        return self::format($value, 'Y-m-d\TH:i');
    }

    /** Current moment in the site zone ("this month", "today" in filters and calendars). */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /**
     * A date someone typed, converted to UTC for storage. Without an explicit offset (a datetime-local value, or
     * "2026-12-01 09:00") it is read in the site zone; with one ("…Z", "…-03:00") the offset wins. Values that are not
     * dates are returned untouched so validation can reject them.
     */
    public static function toStorage(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->utc()->format('Y-m-d\TH:i:s\Z');
        }

        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        try {
            // The zone argument is only a default: PHP keeps an offset or zone written in the string itself.
            $date = CarbonImmutable::parse($value, self::timezone());
        } catch (Throwable) {
            return $value;
        }

        return $date->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** Convert the given keys of $data with toStorage(), leaving absent keys absent. */
    public static function toStorageKeys(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = self::toStorage($data[$key]);
            }
        }

        return $data;
    }

    /** Start and end of a calendar day in the site zone, as UTC instants for queries. */
    public static function dayBoundsUtc(string $date): ?array
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::timezone());
        } catch (Throwable) {
            return null;
        }

        if (! $day instanceof CarbonInterface) {
            return null;
        }

        return [$day->startOfDay()->utc(), $day->endOfDay()->utc()];
    }
}
