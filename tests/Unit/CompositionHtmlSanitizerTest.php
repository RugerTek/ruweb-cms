<?php

namespace Tests\Unit;

use App\Support\CompositionHtmlSanitizer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompositionHtmlSanitizerTest extends TestCase
{
    public function test_fragment_retains_structure_and_only_precompiled_tokens(): void
    {
        $html = (new CompositionHtmlSanitizer)->sanitize('<section class="cms-section fixed cms-grid-2" style="color:red" onclick="evil()"><h1 id="hello">Título</h1><div><p>Copy</p></div></section>');
        $this->assertSame('<section class="cms-section cms-grid-2"><h1 id="hello">Título</h1><div><p>Copy</p></div></section>', $html);
        $this->assertSame($html, (new CompositionHtmlSanitizer)->sanitize($html));
    }

    public function test_executable_and_foreign_markup_is_removed_without_losing_allowed_copy(): void
    {
        $html = (new CompositionHtmlSanitizer)->sanitize('<script>alert(1)</script><svg><script>alert(2)</script></svg><form><p>Form</p></form><custom><p onclick="evil()">Safe</p></custom><!--x--><?php echo 1; ?><iframe src="https://example.com"></iframe>');
        $this->assertSame('<p>Safe</p>', $html);
    }

    public function test_media_has_only_semantic_ids_and_no_persisted_sources(): void
    {
        $html = (new CompositionHtmlSanitizer)->sanitize('<img data-media-id="12" src="/storage/x.png" srcset="x 2x" onerror="evil()" alt="Portrait"><img src="https://external.test/x"><img data-media-id="0">');
        $this->assertSame('<img data-media-id="12" alt="Portrait">', $html);
        $this->assertSame([12], CompositionHtmlSanitizer::mediaIds($html));
    }

    public function test_urls_normalize_and_obfuscated_or_protocol_relative_urls_are_removed(): void
    {
        config(['app.url' => 'https://site.test']);
        $html = (new CompositionHtmlSanitizer)->sanitize('<a href="https://site.test/about?q=1#x" target="_blank">Internal</a><a href="jav&#x61;script:alert(1)">Bad</a><a href="//evil.test">Protocol</a><a href="/\\evil.test">Slash</a><a href="https://other.test/x">Other</a>');
        $this->assertStringContainsString('href="/about?q=1#x"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringNotContainsString('evil.test', $html);
        $this->assertStringContainsString('href="https://other.test/x"', $html);
    }

    public function test_parser_boundary_cannot_escape_sanitization(): void
    {
        $html = (new CompositionHtmlSanitizer)->sanitize('</body></html><script>bad()</script><div onclick="bad()">Allowed</div>');
        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('bad()', $html);
    }

    public function test_size_limit_is_enforced(): void
    {
        $this->expectException(ValidationException::class);
        (new CompositionHtmlSanitizer)->sanitize(str_repeat('x', CompositionHtmlSanitizer::MAX_BYTES + 1));
    }

    public function test_depth_limit_is_enforced(): void
    {
        $this->expectException(ValidationException::class);
        (new CompositionHtmlSanitizer)->sanitize(str_repeat('<div>', 45).'x'.str_repeat('</div>', 45));
    }
}
