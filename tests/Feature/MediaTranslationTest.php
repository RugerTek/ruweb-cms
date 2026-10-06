<?php

namespace Tests\Feature;

use App\Models\ContentRevision;
use App\Models\Locale;
use App\Models\Media;
use App\Models\User;
use App\Services\Revisions\ContentRevisionLedger;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_active' => true, 'is_public' => true]);
        Locale::create(['code' => 'fr', 'name' => 'Français', 'is_active' => false]);
        $this->actingAs(User::factory()->create(['role' => 'editor']));
    }

    private function media(): Media
    {
        return Media::create([
            'filename' => 'shared.svg', 'original_filename' => 'shared.svg', 'path' => 'media/shared.svg',
            'mime_type' => 'image/svg+xml', 'extension' => 'svg', 'size' => 32, 'disk' => 'public',
            'alt' => 'Descripción base', 'title' => 'Título base',
        ]);
    }

    public function test_editor_can_save_multiple_languages_without_duplicating_the_asset(): void
    {
        $media = $this->media();
        $this->putJson(route('admin.media.update', $media), ['metadata' => [
            'es' => ['alt' => 'Paisaje', 'title' => 'Portada'],
            'en' => ['alt' => 'Landscape', 'title' => 'Cover'],
        ]])->assertOk()->assertJsonPath('metadata.en.title', 'Cover')->assertJsonPath('alt', 'Paisaje');
        $this->assertDatabaseCount('media', 1);
        $this->assertSame('media/shared.svg', $media->fresh()->path);
        $this->assertSame($media->url(), $media->fresh()->url());
        $this->assertSame('Landscape', $media->fresh()->localizedAlt('en'));
        $this->assertSame('Cover', $media->fresh()->localizedTitle('en'));
    }

    public function test_empty_translation_inherits_each_field_and_clearing_both_removes_override(): void
    {
        $media = $this->media();
        $media->translations()->create(['locale' => 'en', 'alt' => 'Landscape', 'title' => 'Cover']);
        $this->putJson(route('admin.media.update', $media), ['metadata' => [
            'en' => ['alt' => '', 'title' => 'Cover'], 'es' => ['alt' => 'Nueva descripción'],
        ]])->assertOk();
        $this->assertSame('Nueva descripción', $media->fresh()->localizedAlt('en'));
        $this->assertSame('Cover', $media->fresh()->localizedTitle('en'));
        $this->putJson(route('admin.media.update', $media), ['metadata' => ['en' => ['title' => null]]])->assertOk();
        $this->assertDatabaseCount('media_translations', 0);
        $this->assertSame('Título base', $media->fresh()->localizedTitle('en'));
    }

    public function test_legacy_updates_preserve_secondary_text_and_json_exposes_both_versions(): void
    {
        $media = $this->media();
        $media->translations()->create(['locale' => 'en', 'alt' => 'Landscape', 'title' => 'Cover']);
        $this->putJson(route('admin.media.update', $media), ['alt' => 'Base nueva'])->assertOk();
        $this->getJson(route('admin.media.show', $media).'?locale=en')->assertOk()
            ->assertJsonPath('alt', 'Base nueva')->assertJsonPath('title', 'Título base')
            ->assertJsonPath('localized_alt', 'Landscape')->assertJsonPath('localized_title', 'Cover')
            ->assertJsonPath('metadata.es.alt', 'Base nueva')->assertJsonPath('metadata.en.alt', 'Landscape');
    }

    public function test_library_search_matches_translated_text_and_uses_requested_content_locale(): void
    {
        $media = $this->media();
        $media->translations()->create(['locale' => 'en', 'alt' => 'Landscape', 'title' => 'Cover']);
        $this->getJson(route('admin.media.index').'?search=Landscape&locale=en')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $media->id)
            ->assertJsonPath('data.0.localized_title', 'Cover');
        $this->getJson(route('admin.media.show', $media).'?locale=fr')->assertUnprocessable();
    }

    public function test_invalid_metadata_is_rejected_atomically(): void
    {
        $media = $this->media();
        foreach ([
            ['fr' => ['alt' => 'Inactive']],
            ['xx' => ['alt' => 'Unknown']],
            ['en' => ['alt' => str_repeat('a', 256)]],
            ['en' => ['path' => 'another-file.svg']],
            ['en' => ['title' => ['invalid']]],
        ] as $invalid) {
            $this->putJson(route('admin.media.update', $media), ['metadata' => ['es' => ['alt' => 'Should not save']] + $invalid])
                ->assertUnprocessable();
            $this->assertSame('Descripción base', $media->fresh()->alt);
            $this->assertDatabaseCount('media_translations', 0);
        }
        $this->putJson(route('admin.media.update', $media), ['alt' => 'Ambiguous', 'metadata' => ['en' => ['alt' => 'English']]])
            ->assertUnprocessable();
    }

    public function test_public_image_component_resolves_locale_escapes_alt_and_respects_explicit_copy(): void
    {
        $media = $this->media();
        $media->translations()->create(['locale' => 'en', 'alt' => 'Landscape "<script>"']);
        app()->setLocale('en');
        $html = Blade::render('<x-responsive-img :media="$media" />', ['media' => $media]);
        $this->assertStringContainsString('alt="Landscape &quot;&lt;script&gt;&quot;"', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('alt="Content label"', Blade::render('<x-responsive-img :media="$media" alt="Content label" />', ['media' => $media]));
        app()->setLocale('es');
        $this->assertStringContainsString('alt="Descripción base"', Blade::render('<x-responsive-img :media="$media" />', ['media' => $media]));
    }

    public function test_library_renders_only_editable_languages_and_metadata_help(): void
    {
        $this->media();
        $this->get(route('admin.media.index'))->assertOk()->assertSee('id="detail-locale"', false)
            ->assertSee('English (en)')->assertDontSee('Français (fr)')->assertSee(__('admin.media_metadata_help'));
    }

    public function test_translation_writes_record_a_revision_for_the_same_media(): void
    {
        $media = $this->media();
        ContentRevision::query()->delete();
        app()->forgetInstance(ContentRevisionLedger::class);
        $this->putJson(route('admin.media.update', $media), ['metadata' => ['en' => ['alt' => 'Translated']]])->assertOk();
        $this->assertDatabaseHas('content_revisions', ['entity_type' => Media::class, 'entity_id' => $media->id]);
    }

    public function test_snapshots_restore_translations_and_delete_cascades_to_metadata(): void
    {
        Storage::fake('public');
        $media = $this->media();
        Storage::disk('public')->put($media->path, '<svg></svg>');
        $media->translations()->create(['locale' => 'en', 'alt' => 'Landscape', 'title' => 'Cover']);
        $archive = app(SnapshotService::class)->create('media-translations');
        $media->translations()->update(['alt' => 'Modified']);
        app(SnapshotService::class)->restore($archive);
        $this->assertSame('Landscape', $media->fresh()->localizedAlt('en'));
        $this->assertDatabaseCount('media', 1);
        Storage::disk('public')->assertExists($media->path);
        $media->delete();
        $this->assertDatabaseCount('media_translations', 0);
    }

    public function test_guest_cannot_update_translations(): void
    {
        $media = $this->media();
        auth()->logout();
        $this->putJson(route('admin.media.update', $media), ['metadata' => ['en' => ['alt' => 'Translated']]])->assertNotFound();
        $this->assertDatabaseCount('media_translations', 0);
    }
}
