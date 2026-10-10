<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Validation\ValidationException;

/** Editorial fragments: no executable code, arbitrary styles, or persisted media URLs. */
final class CompositionHtmlSanitizer
{
    public const CLASSES = ['cms-container', 'cms-section', 'cms-stack', 'cms-grid', 'cms-grid-2', 'cms-grid-3', 'cms-card', 'cms-eyebrow', 'cms-title', 'cms-lead', 'cms-muted', 'cms-button', 'cms-button-secondary', 'cms-text-center', 'cms-media', 'cms-split', 'cms-gap-sm', 'cms-gap-lg'];

    public const MAX_BYTES = 200000;

    public const MAX_NODES = 5000;

    public const MAX_DEPTH = 40;

    private const TAGS = ['section', 'article', 'div', 'span', 'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'b', 'em', 'i', 'u', 's', 'a', 'ul', 'ol', 'li', 'blockquote', 'figure', 'figcaption', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'pre', 'code'];

    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'option', 'noscript', 'template', 'svg', 'math', 'head', 'link', 'meta', 'base'];

    public function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        if (strlen($html) > self::MAX_BYTES) {
            $this->fail('Composition HTML exceeds its size limit.');
        }
        [$document, $body] = self::parse($html);
        if (! $body) {
            return '';
        }
        $nodes = 0;
        $this->checkComplexity($body, 0, $nodes);
        $this->cleanChildren($body);
        $output = '';
        foreach ($body->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    /** IDs are discovered with the same parser as sanitization, never by matching markup text. */
    public static function mediaIds(?string $html): array
    {
        if ($html === null || $html === '') {
            return [];
        }
        [$document] = self::parse($html);
        $ids = [];
        foreach ($document->getElementsByTagName('img') as $image) {
            $id = filter_var($image->getAttribute('data-media-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public static function discovery(): array
    {
        return [
            'tags' => self::TAGS,
            'attributes' => [
                '*' => ['class', 'id', 'title'],
                'a' => ['href', 'target', 'rel'],
                'img' => ['data-media-id', 'alt'],
                'th' => ['colspan', 'rowspan', 'scope'],
                'td' => ['colspan', 'rowspan'],
            ],
            'classes' => self::CLASSES,
            'styles' => [],
            'limits' => ['bytes' => self::MAX_BYTES, 'nodes' => self::MAX_NODES, 'depth' => self::MAX_DEPTH],
            'media' => ['tag' => 'img', 'attribute' => 'data-media-id', 'type' => 'positive_integer'],
            'links' => ['schemes' => ['http', 'https', 'mailto', 'tel'], 'internal' => 'root_relative', 'anchors' => true],
            'locale_strategy' => 'whole_fragment',
        ];
    }

    private static function parse(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><head></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return [$document, $document->getElementsByTagName('body')->item(0)];
    }

    private function checkComplexity(DOMNode $node, int $depth, int &$count): void
    {
        if ($depth > self::MAX_DEPTH || ++$count > self::MAX_NODES) {
            $this->fail('Composition HTML exceeds its complexity limit.');
        }
        foreach ($node->childNodes as $child) {
            $this->checkComplexity($child, $depth + 1, $count);
        }
    }

    private function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if (! $node instanceof DOMElement || in_array(strtolower($node->tagName), self::DROP, true)) {
                $parent->removeChild($node);

                continue;
            }
            $this->cleanChildren($node);
            $tag = strtolower($node->tagName);
            if (! in_array($tag, self::TAGS, true)) {
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);

                continue;
            }
            $allowed = ['class', 'id', 'title'];
            $allowed = array_merge($allowed, match ($tag) {
                'a' => ['href', 'target', 'rel'],
                'img' => ['data-media-id', 'alt'],
                'th' => ['colspan', 'rowspan', 'scope'],
                'td' => ['colspan', 'rowspan'],
                default => [],
            });
            foreach (iterator_to_array($node->attributes) as $attribute) {
                if (! in_array(strtolower($attribute->name), $allowed, true)) {
                    $node->removeAttribute($attribute->name);
                }
            }
            $classes = preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [];
            $classes = array_values(array_intersect(array_unique($classes), self::CLASSES));
            $node->removeAttribute('class');
            if ($classes) {
                $node->setAttribute('class', implode(' ', $classes));
            }
            if ($node->hasAttribute('id') && ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D', $node->getAttribute('id'))) {
                $node->removeAttribute('id');
            }
            if ($tag === 'img') {
                $id = filter_var($node->getAttribute('data-media-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    $parent->removeChild($node);

                    continue;
                }
                $node->setAttribute('data-media-id', (string) $id);
                if (! $node->hasAttribute('alt')) {
                    $node->setAttribute('alt', '');
                }
            }
            if ($tag === 'a') {
                $url = $this->safeUrl($node->getAttribute('href'));
                $node->removeAttribute('href');
                if ($url !== null) {
                    $node->setAttribute('href', $url);
                }
                $blank = $node->getAttribute('target') === '_blank';
                $node->removeAttribute('target');
                $node->removeAttribute('rel');
                if ($blank) {
                    $node->setAttribute('target', '_blank');
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            }
            foreach (['colspan', 'rowspan'] as $name) {
                if ($node->hasAttribute($name) && ! preg_match('/^(?:[1-9]|[1-9][0-9])$/D', $node->getAttribute($name))) {
                    $node->removeAttribute($name);
                }
            }
            if ($node->hasAttribute('scope') && ! in_array($node->getAttribute('scope'), ['row', 'col', 'rowgroup', 'colgroup'], true)) {
                $node->removeAttribute('scope');
            }
        }
    }

    private function safeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || str_starts_with($url, '//')) {
            return null;
        }
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return $url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
            return null;
        }
        if (in_array($scheme, ['http', 'https'], true) && ! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        return SiteUrl::toRelative($url);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['content_json' => $message]);
    }
}
