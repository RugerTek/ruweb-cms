<?php

namespace App\Services\Media;

use DOMDocument;
use DOMElement;
use DOMNode;
use InvalidArgumentException;

/** Accept only inert SVG geometry. No CSS, links, foreign documents or external resources. */
class SvgSanitizer
{
    private const ELEMENTS = ['svg', 'g', 'defs', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'title', 'desc', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'pattern'];

    private const ATTRIBUTES = ['id', 'viewBox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points', 'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'transform', 'preserveAspectRatio', 'gradientUnits', 'gradientTransform', 'spreadMethod', 'offset', 'stop-color', 'stop-opacity', 'fx', 'fy', 'fr', 'clip-path', 'clip-rule', 'clipPathUnits', 'mask', 'maskUnits', 'maskContentUnits', 'patternUnits', 'patternContentUnits', 'patternTransform', 'font-family', 'font-size', 'font-weight', 'text-anchor', 'dominant-baseline', 'dx', 'dy', 'rotate', 'textLength', 'lengthAdjust', 'version', 'role', 'aria-label', 'aria-hidden', 'focusable'];

    public function sanitize(string $source): string
    {
        if ($source === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $source)) {
            throw new InvalidArgumentException('Unsafe SVG document.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($source, LIBXML_NONET) || ! $document->documentElement
                || $document->documentElement->localName !== 'svg'
                || $document->documentElement->namespaceURI !== 'http://www.w3.org/2000/svg') {
                throw new InvalidArgumentException('Invalid SVG document.');
            }
            $this->validateNode($document);

            return $document->saveXML($document->documentElement);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function validateNode(DOMNode $node): void
    {
        if (in_array($node->nodeType, [XML_PI_NODE, XML_DOCUMENT_TYPE_NODE, XML_ENTITY_REF_NODE], true)) {
            throw new InvalidArgumentException('Unsafe SVG node.');
        }
        if ($node instanceof DOMElement) {
            if ($node->namespaceURI !== 'http://www.w3.org/2000/svg' || ! in_array($node->localName, self::ELEMENTS, true)) {
                throw new InvalidArgumentException('Unsupported SVG element.');
            }
            foreach ($node->attributes as $attribute) {
                if (($attribute->namespaceURI !== null && $attribute->namespaceURI !== '')
                    || ! in_array($attribute->name, self::ATTRIBUTES, true)) {
                    throw new InvalidArgumentException('Unsupported SVG attribute.');
                }
                $value = $attribute->value;
                // Paint/clip references may only point to an id within this same document.
                if (preg_match('/url\s*\(/i', $value) && ! preg_match('/^url\(#[A-Za-z_][A-Za-z0-9_.:-]*\)$/D', $value)) {
                    throw new InvalidArgumentException('External SVG resource.');
                }
                if (preg_match('/[\\\\\x00-\x08\x0b\x0c\x0e-\x1f]|(?:javascript|data|https?|file):/i', $value)) {
                    throw new InvalidArgumentException('Unsafe SVG attribute value.');
                }
            }
        }
        foreach ($node->childNodes as $child) {
            $this->validateNode($child);
        }
    }
}
