<?php

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Server-side allowlist for rich text produced by the admin editors.
 *
 * The editor presets constrain what an author can insert, but the browser is not a
 * trust boundary: anything reaching `content` — the admin form, the MCP API, an
 * import — is normalised here before it is stored, so the public page only ever
 * renders the same allowlisted markup.
 */
class RichTextSanitizer
{
    /**
     * Allowed elements mapped to the attributes each one may keep.
     *
     * @var array<string, list<string>>
     */
    protected const ALLOWED = [
        'p' => [],
        'br' => [],
        'hr' => [],
        'h2' => ['id'],
        'h3' => ['id'],
        'h4' => ['id'],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => ['cite'],
        'figure' => ['class'],
        'figcaption' => [],
        'img' => ['src', 'alt', 'title', 'width', 'height', 'loading', 'decoding', 'class'],
        'iframe' => ['src', 'width', 'height', 'allow', 'allowfullscreen', 'title'],
        'table' => ['class'],
        'thead' => [],
        'tbody' => [],
        'tfoot' => [],
        'tr' => [],
        'th' => ['colspan', 'rowspan', 'scope'],
        'td' => ['colspan', 'rowspan'],
        'pre' => ['class'],
        'code' => ['class'],
        'span' => ['class'],
    ];

    /** Elements removed together with their contents, not just unwrapped. */
    protected const STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'noscript'];

    /** URL schemes accepted in href and src. */
    protected const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function sanitize(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if (trim($html) === '') {
            return '';
        }

        // Plain-text content predates the rich-text editor; leave it untouched so
        // existing posts keep rendering exactly as they were written.
        if (! $this->looksLikeHtml($html)) {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="rich-text-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('rich-text-root');
        if (! $root) {
            return '';
        }

        $this->cleanChildren($root);

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }

        return trim($output);
    }

    protected function looksLikeHtml(string $value): bool
    {
        return (bool) preg_match('/<[a-z!\/][^>]*>/i', $value);
    }

    protected function cleanChildren(DOMNode $node): void
    {
        // Snapshot first: the list is live while nodes are removed or unwrapped.
        foreach (iterator_to_array($node->childNodes) as $child) {
            $this->cleanNode($child);
        }
    }

    protected function cleanNode(DOMNode $node): void
    {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->nodeName);

            if (in_array($tag, self::STRIP_WITH_CONTENT, true) && ! $this->isAllowedEmbed($node, $tag)) {
                $node->parentNode?->removeChild($node);

                return;
            }

            if (! array_key_exists($tag, self::ALLOWED)) {
                $this->unwrap($node);

                return;
            }

            $this->cleanAttributes($node, $tag);
        } elseif ($node->nodeType === XML_COMMENT_NODE) {
            $node->parentNode?->removeChild($node);

            return;
        }

        if ($node->hasChildNodes()) {
            $this->cleanChildren($node);
        }
    }

    /** Media embeds survive only when they point at a configured provider. */
    protected function isAllowedEmbed(DOMElement $node, string $tag): bool
    {
        if ($tag !== 'iframe') {
            return false;
        }

        $host = parse_url((string) $node->getAttribute('src'), PHP_URL_HOST);
        if (! is_string($host)) {
            return false;
        }

        $host = strtolower(ltrim($host, 'www.'));

        foreach ((array) config('cms.rich_text.allowed_embed_hosts', []) as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed !== '' && ($host === $allowed || str_ends_with($host, '.' . $allowed))) {
                return true;
            }
        }

        return false;
    }

    /** Replace an unknown element with its children, preserving the text. */
    protected function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }

        while ($node->firstChild) {
            $child = $node->firstChild;
            $parent->insertBefore($child, $node);
            $this->cleanNode($child);
        }

        $parent->removeChild($node);
    }

    protected function cleanAttributes(DOMElement $node, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];

        foreach (iterator_to_array($node->attributes) as $attribute) {
            /** @var DOMAttr $attribute */
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, $allowed, true)) {
                $node->removeAttribute($attribute->nodeName);

                continue;
            }

            if (in_array($name, ['href', 'src'], true)) {
                if (! $this->isSafeUrl($attribute->nodeValue)) {
                    $node->removeAttribute($attribute->nodeName);

                    continue;
                }

                // Links and images pointing at this very site are stored
                // root-relative so content survives a change of domain.
                $node->setAttribute($attribute->nodeName, (string) SiteUrl::toRelative($attribute->nodeValue));
            }
        }

        // Links opening a new tab must not hand the opener to the destination.
        if ($tag === 'a' && $node->getAttribute('target') === '_blank') {
            $node->setAttribute('rel', 'noopener noreferrer');
        }

        if ($tag === 'img') {
            if (! $node->hasAttribute('alt')) {
                $node->setAttribute('alt', '');
            }
            $node->setAttribute('loading', 'lazy');
            $node->setAttribute('decoding', 'async');
        }
    }

    protected function isSafeUrl(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        // Relative URLs and in-page anchors carry no scheme and are safe.
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme === null || $scheme === false) {
            // No scheme and not clearly relative: reject rather than guess, which
            // also blocks obfuscated "javascript&colon;" style payloads.
            return ! preg_match('/^[a-z0-9+.-]*\s*:/i', $url);
        }

        return in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true);
    }
}
