<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Validation\ValidationException;

/** Canonicalize composition at every write boundary, independently of template selection. */
final class PageComposition
{
    public static function normalize(array $content): array
    {
        foreach (['sections', 'sections.content', 'sections.composition'] as $path) {
            $value = data_get($content, $path);
            if ($value !== null && ! is_array($value)) {
                throw ValidationException::withMessages(['content_json.'.$path => 'Expected an object.']);
            }
        }
        if (array_key_exists('html', $content['sections']['content'] ?? [])) {
            $html = $content['sections']['content']['html'];
            if ($html !== null && ! is_string($html)) {
                throw ValidationException::withMessages(['content_json.sections.content.html' => 'Expected an HTML fragment.']);
            }
            $content['sections']['content']['html'] = app(CompositionHtmlSanitizer::class)->sanitize($html);
        }
        if (array_key_exists('blocks', $content['sections']['composition'] ?? [])) {
            $blocks = $content['sections']['composition']['blocks'];
            // Explicit null remains a removal instruction for ContentPatch.
            if ($blocks === null) {
                return $content;
            }
            if (! is_array($blocks)) {
                throw ValidationException::withMessages(['content_json.sections.composition.blocks' => 'Expected a block list.']);
            }
            $content['sections']['composition']['blocks'] = app(CompositionBlocks::class)->normalize($blocks);
        }

        return $content;
    }

    public static function mediaIds(array $content): array
    {
        $ids = CompositionBlocks::mediaIds(is_array($blocks = data_get($content, 'sections.composition.blocks')) ? $blocks : []);
        $walk = function (mixed $value, string|int $key = '') use (&$walk, &$ids): void {
            if (is_array($value)) {
                foreach ($value as $childKey => $child) {
                    $walk($child, $childKey);
                }
            } elseif ($key === 'html' && is_string($value)) {
                $ids = array_merge($ids, app(CompositionHtmlSanitizer::class)->mediaIds($value));
            }
        };
        $walk($content);

        return array_values(array_unique($ids));
    }

    public static function validateMedia(array $content): void
    {
        $ids = self::mediaIds($content);
        if ($ids !== [] && Media::whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['content_json' => 'A referenced composition media ID does not exist.']);
        }
    }
}
