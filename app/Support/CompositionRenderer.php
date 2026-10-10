<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

final class CompositionRenderer
{
    /** Sanitize again at render time, including old versions and imported content. */
    public function html(?string $html): HtmlString
    {
        $safe = app(CompositionHtmlSanitizer::class)->sanitize($html) ?? '';
        $ids = CompositionHtmlSanitizer::mediaIds($safe);
        $media = Media::query()->whereIn('id', $ids)->get()->keyBy('id');
        $document = $this->document($safe);
        foreach (iterator_to_array($document->getElementsByTagName('img')) as $node) {
            $image = $media->get((int) $node->getAttribute('data-media-id'));
            if (! $image) {
                $node->parentNode->removeChild($node);

                continue;
            }
            $markup = view('components.responsive-img', [
                'media' => $image,
                'alt' => $node->getAttribute('alt'),
                'class' => $node->getAttribute('class') ?: 'cms-media',
            ])->render();
            $resolved = $this->document($markup)->getElementsByTagName('img')->item(0);
            if ($resolved) {
                foreach (['id', 'title'] as $attribute) {
                    if ($node->hasAttribute($attribute)) {
                        $resolved->setAttribute($attribute, $node->getAttribute($attribute));
                    }
                }
                $node->parentNode->replaceChild($document->importNode($resolved, true), $node);
            }
        }
        $rendered = '';
        foreach ($document->getElementsByTagName('body')->item(0)?->childNodes ?? [] as $child) {
            $rendered .= $document->saveHTML($child);
        }

        return new HtmlString($rendered);
    }

    private function document(string $html): \DOMDocument
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><head></head><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    public function blocks(array $blocks): HtmlString
    {
        try {
            $blocks = app(CompositionBlocks::class)->normalize($blocks);
        } catch (ValidationException $exception) {
            return new HtmlString('');
        }
        $output = '';
        foreach ($blocks as $block) {
            if (! is_array($block) || ! ($block['is_visible'] ?? true)) {
                continue;
            }
            $id = (string) ($block['id'] ?? '');
            $output .= '<div data-cms-block="'.e($id).'">';
            if (($block['type'] ?? null) === 'html') {
                $output .= $this->html($block['html'] ?? null)->toHtml();
            } else {
                $component = config('cms.composition.components.'.($block['component'] ?? ''));
                if (is_array($component) && ($component['type'] ?? null) === ($block['type'] ?? null)
                    && isset($component['view']) && view()->exists($component['view'])) {
                    $output .= view($component['view'], ['params' => $block['params'] ?? [], 'blockId' => $id])->render();
                }
            }
            $output .= '</div>';
        }

        return new HtmlString($output);
    }
}
