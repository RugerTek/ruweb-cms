<?php

namespace App\Helpers;

use App\Models\Media;
use App\Models\Setting;

class ContentHelper
{
    /**
     * Render stored rich text for the public page.
     *
     * Content written before the rich-text editor is plain text, so it still gets
     * the escape-and-linebreak treatment; HTML is already sanitised on save and is
     * emitted as-is.
     */
    public static function richText(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        if (! preg_match('/<[a-z!\/][^>]*>/i', $value)) {
            return nl2br(e($value));
        }

        return app(\App\Support\RichTextSanitizer::class)->sanitize($value) ?? '';
    }

    public static function metaTitle(array $meta, ?string $default = null): string
    {
        $siteName = Setting::getLocalized('site_name') ?: config('app.name');

        return ($meta['title'] ?? null)
            ?: $default
            ?: $siteName;
    }

    public static function metaDescription(array $meta, ?string $default = null): string
    {
        return $meta['description']
            ?? $default
            ?? Setting::getLocalized('site_description')
            ?? '';
    }

    public static function metaImage(array $meta): ?string
    {
        $mediaId = $meta['featured_image'] ?? null;

        if ($mediaId) {
            $media = Media::find($mediaId);
            if ($media) {
                return $media->url();
            }
        }

        $fallback = Setting::getLocalized('default_social_image');
        if ($fallback instanceof Media) {
            return $fallback->url();
        }
        if (is_numeric($fallback)) {
            return Media::find($fallback)?->url();
        }
        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }

        return null;
    }
}
