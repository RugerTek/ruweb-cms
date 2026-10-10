<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Support\CompositionRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompositionFrontendTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_and_canvas_keep_seo_but_only_html_has_site_layout(): void
    {
        foreach (['html', 'empty'] as $template) {
            $page = Page::create(['title' => 'Composition '.$template, 'slug' => 'composition-'.$template, 'template_key' => $template, 'status' => 'published', 'published_at' => now()->subMinute(), 'content_json' => ['sections' => ['content' => ['html' => '<h1 class="cms-title">Editorial heading</h1><script>unsafe_marker()</script>']]]]);
            $response = $this->get('/'.$page->slug)->assertOk()->assertSee('Editorial heading')->assertDontSee('unsafe_marker')->assertSee('<meta name="viewport"', false)->assertSee('<title>', false);
            if ($template === 'html') {
                $response->assertSee('<header', false)->assertSee('<footer', false);
            } else {
                $response->assertDontSee('<header', false)->assertDontSee('<footer', false);
            }
        }
    }

    public function test_media_markers_resolve_and_components_escape_saved_copy(): void
    {
        $media = Media::create(['filename' => 'photo.jpg', 'original_filename' => 'photo.jpg', 'path' => 'media/photo.jpg', 'mime_type' => 'image/jpeg', 'extension' => 'jpg', 'size' => 100, 'width' => 600, 'height' => 400, 'alt' => 'Localized image']);
        $html = app(CompositionRenderer::class)->html('<img data-media-id="'.$media->id.'" class="cms-media">')->toHtml();
        $this->assertStringContainsString(e($media->url()), $html);
        $this->assertStringContainsString('Localized image', $html);
        $altHtml = app(CompositionRenderer::class)->html('<img data-media-id="'.$media->id.'" alt="x &gt; y &quot;quoted&quot;" id="image-anchor" title="a &gt; b">')->toHtml();
        $document = new \DOMDocument;
        @$document->loadHTML($altHtml);
        $this->assertSame('x > y "quoted"', $document->getElementsByTagName('img')->item(0)->getAttribute('alt'));
        $this->assertSame(1, $document->getElementsByTagName('img')->length);
        $this->assertSame('image-anchor', $document->getElementsByTagName('img')->item(0)->getAttribute('id'));
        $this->assertSame('a > b', $document->getElementsByTagName('img')->item(0)->getAttribute('title'));
        $blocks = [
            ['id' => 'accordion-main', 'type' => 'interactive', 'version' => 1, 'is_visible' => true, 'component' => 'accordion', 'params' => ['heading' => 'Questions', 'items' => [['question' => '<script>literal</script>', 'answer' => 'Answer']], 'initially_open' => '0']],
            ['id' => 'hidden-block', 'type' => 'html', 'version' => 1, 'is_visible' => false, 'html' => '<p>Hidden copy</p>'],
        ];
        $rendered = app(CompositionRenderer::class)->blocks($blocks)->toHtml();
        $this->assertStringContainsString('data-cms-accordion', $rendered);
        $this->assertStringContainsString('&lt;script&gt;literal&lt;/script&gt;', $rendered);
        $this->assertStringContainsString(' open ', $rendered);
        $this->assertStringNotContainsString('Hidden copy', $rendered);
    }
}
