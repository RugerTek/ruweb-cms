<?php

namespace Tests\Feature;

use App\Http\Middleware\ProtectLiveEditor;
use App\Models\Locale;
use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use App\Services\Content\TranslationOperations;
use App\Services\Editorial\EditorialHistory;
use App\Services\Editorial\EditorialOperations;
use App\Services\Media\MediaUsage;
use App\Services\Pages\PageOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompositionOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bearer_fragment_is_sanitized_media_is_protected_and_translation_reviews_whole_fragment(): void
    {
        foreach (['es', 'en'] as $locale) {
            Locale::create(['code' => $locale, 'name' => $locale, 'is_base' => $locale === 'es', 'is_active' => true, 'is_public' => true]);
        }
        $actor = User::factory()->create(['role' => 'admin']);
        $token = $actor->generateMcpApiKey();
        $media = Media::create(['filename' => 'composition.webp', 'original_filename' => 'composition.webp', 'path' => 'media/composition.webp', 'mime_type' => 'image/webp', 'extension' => 'webp', 'size' => 10, 'disk' => 'public']);
        $html = '<h1>Safe heading</h1><div class="cms-grid-2"><p onclick="window.bad=1">Admitted</p><img data-media-id="'.$media->id.'" src="https://evil.test/x" onerror="window.bad=2"></div><script>window.bad=3</script>';
        $created = $this->withToken($token)->postJson('/mcp/pages/drafts', ['title' => 'HTML', 'slug' => 'html-test', 'template_key' => 'html', 'content_json' => ['sections' => ['content' => ['html' => $html]]]])->assertCreated();
        $page = Page::findOrFail($created->json('data.id'));
        $stored = $page->sections()['content']['html'];
        $this->assertStringContainsString('<h1>Safe heading</h1>', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('https://evil.test', $stored);
        $this->assertNotEmpty(app(MediaUsage::class)->references($media));
        $translation = app(TranslationOperations::class)->create($actor, $page, 'en');
        $this->assertSame($stored, $translation->sections()['content']['html']);
        $page = app(PageOperations::class)->save($actor, ['content_json' => ['sections' => ['content' => ['html' => '<h1>Changed heading</h1>']]]], $page, draftOnly: true);
        $this->assertSame(['sections.content.html'], $translation->fresh()->staleTranslatableFields());
        $this->assertNotEmpty(array_filter(app(MediaUsage::class)->references($media), fn ($ref) => $ref['type'] === 'version'));
    }

    public function test_public_html_edits_use_proposals_and_restore_sanitizes_historical_payload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $page = app(PageOperations::class)->save($admin, ['title' => 'Canvas', 'slug' => 'canvas', 'template_key' => 'empty', 'content_json' => ['sections' => ['content' => ['html' => '<h1>Original</h1>']]]], draftOnly: true);
        $page = app(PageOperations::class)->publish($admin, $page);
        $history = app(EditorialHistory::class);
        $revision = $history->fingerprint($page);
        $result = app(EditorialOperations::class)->write($editor, 'pages', $page->id, 'propose', ['expected_revision' => $revision, 'operation_key' => 'composition-propose', 'changes' => ['content_json' => ['sections' => ['content' => ['html' => '<h1 onclick="window.bad=1">Proposal</h1><script>window.bad=2</script>']]]]]);
        $this->assertSame('<h1>Original</h1>', $page->fresh()->sections()['content']['html']);
        $proposal = DB::table('editorial_versions')->where('id', $result['version']['id'])->first();
        $this->assertStringNotContainsString('onclick', $proposal->payload);
        $payload = json_decode($proposal->payload, true);
        $payload['attributes']['content_json']['sections']['content']['html'] = '<h1 onmouseover="window.bad=3">Historical</h1><script>window.bad=4</script>';
        DB::table('editorial_versions')->where('id', $proposal->id)->update(['payload' => json_encode($payload)]);
        app(EditorialOperations::class)->write($admin, 'pages', $page->id, 'restore', ['expected_revision' => $revision, 'operation_key' => 'composition-restore', 'version_id' => $proposal->id, 'mode' => 'proposal']);
        $restored = json_decode(DB::table('editorial_versions')->latest('id')->value('payload'), true);
        $this->assertSame('<h1>Historical</h1>', $restored['attributes']['content_json']['sections']['content']['html']);
        $this->assertSame('published', $page->fresh()->status);
    }

    public function test_stable_blocks_survive_reordering_and_generic_full_editor_cannot_convert_them(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $blocks = [
            ['id' => 'first', 'type' => 'html', 'html' => '<h1>First</h1>'],
            ['id' => 'second', 'type' => 'interactive', 'component' => 'accordion', 'params' => ['items' => [['question' => 'Question', 'answer' => 'Answer']], 'initially_open' => 0]],
        ];
        $page = app(PageOperations::class)->save($actor, ['title' => 'Blocks', 'slug' => 'blocks', 'template_key' => 'composed', 'content_json' => ['sections' => ['composition' => ['blocks' => $blocks]]]], draftOnly: true);
        $blocks = array_reverse($page->sections()['composition']['blocks']);
        $blocks[1]['is_visible'] = false;
        $page = app(PageOperations::class)->save($actor, ['content_json' => ['sections' => ['composition' => ['blocks' => $blocks]]]], $page, draftOnly: true);
        $this->assertSame(['second', 'first'], array_column($page->sections()['composition']['blocks'], 'id'));
        $this->assertFalse($page->sections()['composition']['blocks'][1]['is_visible']);
        $this->actingAs($actor)->get(route('admin.pages.edit', $page))->assertRedirect(route('admin.editorial.index', ['pages', $page->id]));
        $this->withoutMiddleware(ProtectLiveEditor::class)->actingAs($actor)->putJson(route('admin.pages.update', $page), ['title' => 'Convert', 'slug' => 'blocks', 'status' => 'draft', 'template_key' => 'default'])->assertStatus(422);
        $this->assertSame('composed', $page->fresh()->template_key);
    }

    public function test_block_copy_fingerprint_ignores_order_visibility_media_and_behavior(): void
    {
        $blocks = [
            ['id' => 'callout', 'type' => 'structured', 'component' => 'callout', 'params' => ['heading' => 'Copy', 'body' => 'Body']],
            ['id' => 'accordion', 'type' => 'interactive', 'component' => 'accordion', 'params' => ['items' => [['question' => 'Question', 'answer' => 'Answer']], 'initially_open' => 0]],
        ];
        $page = Page::create(['title' => 'Fingerprint', 'slug' => 'fingerprint', 'template_key' => 'composed', 'status' => 'draft', 'content_json' => ['sections' => ['composition' => ['blocks' => $blocks]]]]);
        $before = $page->translatableFingerprint();
        $asset = Media::create(['filename' => 'copy.webp', 'original_filename' => 'copy.webp', 'path' => 'media/copy.webp', 'mime_type' => 'image/webp', 'extension' => 'webp', 'size' => 10, 'disk' => 'public']);
        $blocks[0]['params']['image_id'] = $asset->id;
        $blocks[0]['is_visible'] = false;
        $blocks[1]['params']['initially_open'] = null;
        $page->content_json = ['sections' => ['composition' => ['blocks' => array_reverse($blocks)]]];
        $this->assertSame($before, $page->translatableFingerprint());
        $blocks[1]['params']['items'][0]['answer'] = 'Changed copy';
        $page->content_json = ['sections' => ['composition' => ['blocks' => $blocks]]];
        $this->assertNotSame($before, $page->translatableFingerprint());
    }

    public function test_malformed_composition_returns_validation_errors_and_null_patch_removes_blocks(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $token = $actor->generateMcpApiKey();
        foreach (['content', 'composition'] as $section) {
            $this->withToken($token)->postJson('/mcp/pages/drafts', ['title' => 'Malformed', 'slug' => 'malformed-'.$section, 'template_key' => 'html', 'content_json' => ['sections' => [$section => 'wrong']]])->assertUnprocessable();
        }
        $page = app(PageOperations::class)->save($actor, ['title' => 'Remove', 'slug' => 'remove-blocks', 'template_key' => 'composed', 'content_json' => ['sections' => ['composition' => ['blocks' => [['id' => 'one', 'type' => 'html', 'html' => '<p>Copy</p>']]]]]], draftOnly: true);
        $page = app(PageOperations::class)->save($actor, ['content_json' => ['sections' => ['composition' => ['blocks' => null]]]], $page, draftOnly: true);
        $this->assertArrayNotHasKey('blocks', $page->sections()['composition']);
    }

    public function test_html_reference_is_kept_by_history_after_all_current_content_unlinks_it(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $asset = Media::create(['filename' => 'history.webp', 'original_filename' => 'history.webp', 'path' => 'media/history.webp', 'mime_type' => 'image/webp', 'extension' => 'webp', 'size' => 10, 'disk' => 'public']);
        $page = app(PageOperations::class)->save($actor, ['title' => 'Historical image', 'slug' => 'historical-image', 'template_key' => 'html', 'content_json' => ['sections' => ['content' => ['html' => '<img data-media-id="'.$asset->id.'">']]]], draftOnly: true);
        app(PageOperations::class)->save($actor, ['content_json' => ['sections' => ['content' => ['html' => '<h1>Image removed</h1>']]]], $page, draftOnly: true);
        $references = app(MediaUsage::class)->references($asset);
        $this->assertNotEmpty(array_filter($references, fn ($reference) => $reference['type'] === 'version'));
        $this->assertEmpty(array_filter($references, fn ($reference) => $reference['type'] === 'pages'));
        $this->actingAs($actor)->deleteJson(route('admin.media.destroy', $asset))->assertUnprocessable();
        $this->assertModelExists($asset);
    }
}
