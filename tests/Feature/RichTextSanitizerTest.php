<?php

namespace Tests\Feature;

use App\Support\RichTextSanitizer;
use Tests\TestCase;

class RichTextSanitizerTest extends TestCase
{
    protected function sanitize(?string $html): ?string
    {
        return app(RichTextSanitizer::class)->sanitize($html);
    }

    public function test_it_keeps_the_markup_the_editor_preset_produces(): void
    {
        $html = '<h2>Title</h2><p>Some <strong>bold</strong> and <em>italic</em> text.</p>'
            . '<ul><li>One</li><li>Two</li></ul><blockquote>Quoted</blockquote>';

        $this->assertSame($html, $this->sanitize($html));
    }

    public function test_it_removes_scripts_entirely(): void
    {
        $result = $this->sanitize('<p>Before</p><script>alert(1)</script><p>After</p>');

        $this->assertStringNotContainsString('script', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringContainsString('Before', $result);
        $this->assertStringContainsString('After', $result);
    }

    public function test_it_strips_event_handler_attributes(): void
    {
        $result = $this->sanitize('<p onclick="steal()">Text</p><img src="/a.png" onerror="steal()">');

        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('onerror', $result);
        $this->assertStringContainsString('Text', $result);
    }

    public function test_it_rejects_javascript_urls(): void
    {
        $result = $this->sanitize('<a href="javascript:alert(1)">Click</a>');

        $this->assertStringNotContainsString('javascript', $result);
        $this->assertStringContainsString('Click', $result);
    }

    public function test_it_rejects_data_urls_on_images(): void
    {
        $result = $this->sanitize('<img src="data:text/html;base64,PHNjcmlwdD4=">');

        $this->assertStringNotContainsString('data:', $result);
    }

    public function test_it_keeps_relative_and_anchor_links(): void
    {
        $result = $this->sanitize('<p><a href="/blog">Blog</a> <a href="#top">Top</a></p>');

        $this->assertStringContainsString('href="/blog"', $result);
        $this->assertStringContainsString('href="#top"', $result);
    }

    public function test_it_hardens_links_that_open_a_new_tab(): void
    {
        $result = $this->sanitize('<a href="https://example.com" target="_blank">Out</a>');

        $this->assertStringContainsString('rel="noopener noreferrer"', $result);
    }

    public function test_it_unwraps_unknown_elements_but_keeps_their_text(): void
    {
        $result = $this->sanitize('<p>Hello <marquee>world</marquee></p>');

        $this->assertStringNotContainsString('marquee', $result);
        $this->assertStringContainsString('world', $result);
    }

    public function test_it_keeps_embeds_from_allowed_hosts_only(): void
    {
        $allowed = $this->sanitize('<iframe src="https://www.youtube.com/embed/abc"></iframe>');
        $blocked = $this->sanitize('<iframe src="https://evil.example/embed"></iframe>');

        $this->assertStringContainsString('youtube.com/embed/abc', $allowed);
        $this->assertStringNotContainsString('evil.example', $blocked);
    }

    public function test_it_adds_lazy_loading_and_an_alt_to_images(): void
    {
        $result = $this->sanitize('<img src="/storage/media/a.webp">');

        $this->assertStringContainsString('loading="lazy"', $result);
        $this->assertStringContainsString('decoding="async"', $result);
        $this->assertStringContainsString('alt=""', $result);
    }

    public function test_plain_text_content_is_left_untouched(): void
    {
        $plain = "First line\nSecond line";

        $this->assertSame($plain, $this->sanitize($plain));
    }

    public function test_it_handles_null_and_empty_values(): void
    {
        $this->assertNull($this->sanitize(null));
        $this->assertSame('', $this->sanitize('   '));
    }
}
