<?php

namespace App\Support;

use App\Models\Locale;

/**
 * Build the URL of a named public route for the current locale: the base
 * locale uses the unprefixed route, every other locale the `localized.`
 * variant with the `{locale}` prefix.
 */
final class LocalizedRoute
{
    public static function to(string $name, array $parameters = []): string
    {
        $locale = app()->getLocale();

        if ($locale === Locale::baseCode()) {
            return route($name, $parameters);
        }

        return route('localized.'.$name, ['locale' => $locale] + $parameters);
    }
}
