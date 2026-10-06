<?php

namespace App\Support;

use App\Models\Locale;
use App\Models\Page;

/**
 * Resolves a semantic button destination stored as either an internal page
 * reference (translation group id) or a custom URI, following the "Button
 * Destination Selector" rules in docs/admin/live-editor-ui-ux.md.
 *
 * The admin partial `admin.partials._link-destination` produces the two
 * values; templates call `resolve()` at render time so one selection yields
 * the right localized URL on every public locale.
 */
class LinkDestination
{
    public const CUSTOM = 'custom';

    /** URI forms accepted for a custom destination. */
    public const CUSTOM_URL_PATTERN = '#^(https?://|/|\#|mailto:|tel:)#i';

    /**
     * @param  string|null  $pageRef  translation_group_id of the target page
     * @param  string|null  $customUrl  manual destination used when no page is referenced
     */
    public static function resolve(?string $pageRef, ?string $customUrl, ?string $locale = null): ?string
    {
        $pageRef = trim((string) $pageRef);

        if ($pageRef !== '' && $pageRef !== self::CUSTOM) {
            return self::resolvePage($pageRef, $locale ?: app()->getLocale())?->url();
        }

        $customUrl = trim((string) $customUrl);

        return $customUrl !== '' && preg_match(self::CUSTOM_URL_PATTERN, $customUrl) ? $customUrl : null;
    }

    /**
     * The published page for the requested locale, falling back to the base
     * locale so a missing translation never produces a broken link.
     */
    public static function resolvePage(string $translationGroupId, string $locale): ?Page
    {
        $page = Page::published()
            ->where('translation_group_id', $translationGroupId)
            ->where('locale', $locale)
            ->first();

        if ($page || $locale === Locale::baseCode()) {
            return $page;
        }

        return Page::published()
            ->where('translation_group_id', $translationGroupId)
            ->where('locale', Locale::baseCode())
            ->first();
    }

    /**
     * Options for the destination select: published base-locale pages keyed
     * by translation group id, labelled with their client-facing title.
     *
     * @return array<string, string>
     */
    public static function referenceForUrl(?string $url): ?string
    {
        $url = SiteUrl::toRelative($url);
        foreach (Page::whereNotNull('translation_group_id')->get() as $page) {
            if ($url === $page->url()) {
                return $page->translation_group_id;
            }
        }

        return null;
    }

    public static function pageOptions(): array
    {
        return Page::published()
            ->where('locale', Locale::baseCode())
            ->whereNotNull('translation_group_id')
            ->orderBy('title')
            ->get(['title', 'translation_group_id'])
            ->mapWithKeys(fn (Page $page) => [$page->translation_group_id => $page->title])
            ->all();
    }
}
