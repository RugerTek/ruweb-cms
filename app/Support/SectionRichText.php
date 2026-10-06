<?php

namespace App\Support;

use DOMElement;
use Illuminate\Support\HtmlString;

/** The same minimal allowlists used by the section TinyMCE presets. */
class SectionRichText extends RichTextSanitizer
{
    public function __construct(private string $preset = 'section-body') {}

    protected function allowedElements(): array
    {
        $inline = ['p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'a' => ['href', 'title', 'target', 'rel']];

        return $this->preset === 'short-description' ? $inline : $inline + [
            'a' => ['href', 'title', 'target', 'rel'], 'ul' => [], 'ol' => [], 'li' => [],
        ];
    }

    protected function isAllowedEmbed(DOMElement $node, string $tag): bool
    {
        return false;
    }

    public static function comparisonValue(?string $value, string $preset): string
    {
        $html = (string) self::render($value, $preset);
        if (preg_match('/^<p>(?:(?!<\/p>)[\s\S])*<\/p>$/', $html)) {
            $html = substr($html, 3, -4);
        }
        $html = preg_replace('/<br\s*\/?>(?:\n)?/i', "\n", $html);

        return trim(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function render(?string $value, string $preset = 'section-body'): HtmlString
    {
        $sanitizer = new self($preset);
        $html = $sanitizer->sanitize($value) ?? '';

        return new HtmlString($sanitizer->looksLikeHtml($html) ? $html : nl2br(e($html)));
    }
}
