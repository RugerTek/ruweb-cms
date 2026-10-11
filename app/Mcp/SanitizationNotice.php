<?php

namespace App\Mcp;

use App\Services\Content\ContentSchemaRegistry;
use App\Support\CompositionHtmlSanitizer;
use App\Support\RichTextSanitizer;
use App\Support\SectionRichText;

/** Tell the caller which submitted markup the write boundary discarded, instead of a silent success. */
final class SanitizationNotice
{
    /** Page content: composition HTML plus every schema field the template marks as rich text. */
    public static function forContent(mixed $content, ?string $templateKey = null): array
    {
        if (! is_array($content)) {
            return [];
        }
        $warnings = [];
        foreach (app(ContentSchemaRegistry::class)->forPageTemplate((string) $templateKey) ?? [] as $path => $field) {
            if (isset($field['rich_text']) && ! str_contains($path, '*') && is_string($value = data_get($content, $path))) {
                $warnings = [...$warnings, ...self::warning('content_json.'.$path, (new SectionRichText($field['rich_text']))->removals($value))];
            }
        }
        $fragments = [];
        if (is_string($html = data_get($content, 'sections.content.html'))) {
            $fragments['content_json.sections.content.html'] = $html;
        }
        foreach ((array) data_get($content, 'sections.composition.blocks', []) as $index => $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'html' && is_string($block['html'] ?? null)) {
                $fragments['content_json.sections.composition.blocks.'.$index.'.html'] = $block['html'];
            }
        }
        foreach ($fragments as $field => $fragment) {
            $warnings = [...$warnings, ...self::warning($field, app(CompositionHtmlSanitizer::class)->removals($fragment))];
        }

        return $warnings;
    }

    /** Post body rich text. */
    public static function forPost(mixed $content): array
    {
        return is_string($content) ? self::warning('content', app(RichTextSanitizer::class)->removals($content)) : [];
    }

    private static function warning(string $field, array $removed): array
    {
        return $removed === [] ? [] : [['code' => 'markup_removed', 'field' => $field, 'removed' => $removed,
            'message' => 'Disallowed markup was removed; the stored value differs from the submitted one. Allowed markup: cms_capabilities.composition.html_rules for composition HTML, docs/admin/rich-text.md for rich text.']];
    }
}
