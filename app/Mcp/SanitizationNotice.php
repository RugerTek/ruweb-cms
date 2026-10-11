<?php

namespace App\Mcp;

use App\Support\CompositionHtmlSanitizer;

/** Tell the caller which submitted composition markup the write boundary discarded, instead of a silent success. */
final class SanitizationNotice
{
    public static function forContent(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
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
        $warnings = [];
        foreach ($fragments as $field => $fragment) {
            $removed = app(CompositionHtmlSanitizer::class)->removals($fragment);
            if ($removed !== []) {
                $warnings[] = ['code' => 'markup_removed', 'field' => $field, 'removed' => $removed,
                    'message' => 'Disallowed markup was removed; the stored fragment differs from the submitted one. Allowed markup: cms_capabilities.composition.html_rules.'];
            }
        }

        return $warnings;
    }
}
