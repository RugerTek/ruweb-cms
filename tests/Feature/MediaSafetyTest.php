<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Media\SvgSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MediaSafetyTest extends TestCase
{
    use RefreshDatabase;

    public static function unsafeSvg(): array
    {
        return array_map(fn ($body) => [$body], [
            '<script>alert(1)</script>',
            '<a href="javascript:alert(1)"><text>Click</text></a>',
            '<foreignObject><iframe xmlns="http://www.w3.org/1999/xhtml" src="https://example.com"/></foreignObject>',
            '<image href="data:image/svg+xml,anything"/>',
            '<rect style="fill:url(https://example.com/track)"/>',
            '<rect fill="url(https://example.com/track)"/>',
            '<rect onload="alert(1)"/>',
            '<animate attributeName="href" values="javascript:alert(1)"/>',
            '<?xml-stylesheet href="https://example.com/evil.css"?>',
        ]);
    }

    #[DataProvider('unsafeSvg')]
    public function test_active_svg_uploads_are_rejected_without_storing_files(string $body): void
    {
        Storage::fake('public');
        $file = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg">'.$body.'</svg>');
        $this->actingAs(User::factory()->create(['role' => 'editor']))
            ->postJson(route('admin.media.store'), ['file' => $file])->assertUnprocessable();
        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_inert_svg_gradients_survive_and_entities_are_rejected(): void
    {
        $sanitizer = app(SvgSanitizer::class);
        $safe = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><defs><linearGradient id="a"><stop offset="0" stop-color="red"/></linearGradient></defs><rect width="10" height="10" fill="url(#a)"/></svg>';
        $this->assertStringContainsString('fill="url(#a)"', $sanitizer->sanitize($safe));
        $this->expectException(\InvalidArgumentException::class);
        $sanitizer->sanitize('<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>');
    }

    public function test_safe_svg_upload_is_canonicalized_and_stored(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.media.store'), ['file' => UploadedFile::fake()->createWithContent('logo.svg', '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0h10v10z" fill="#fff"/></svg>')])
            ->assertCreated();
        $media = Media::firstOrFail();
        $this->assertStringStartsWith('<svg', Storage::disk('public')->get($media->path));
    }

    public function test_section_references_block_admin_and_orphan_cleanup_deletion(): void
    {
        $media = $this->asset();
        Page::create(['title' => 'Draft hero', 'slug' => 'draft-hero', 'template_key' => 'home', 'status' => 'draft', 'locale' => 'es', 'content_json' => ['sections' => ['hero' => ['image_id' => $media->id]]]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('admin.media.show', $media))->assertJsonPath('usage.0.field', 'sections.hero.image_id');
        $this->deleteJson(route('admin.media.destroy', $media))->assertUnprocessable();
        $this->artisan('cms:media:audit-orphans', ['--delete' => true])->assertSuccessful();
        Storage::disk('public')->assertExists($media->path);
        $this->assertModelExists($media);
    }

    public function test_localized_settings_and_pivots_block_deletion(): void
    {
        $media = $this->asset();
        $setting = Setting::create(['key' => 'site_logo', 'type' => 'media', 'group' => 'general']);
        $setting->translations()->create(['locale' => 'en', 'value' => (string) $media->id]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->deleteJson(route('admin.media.destroy', $media))->assertUnprocessable();
        $setting->translations()->delete();
        $page = Page::create(['title' => 'Cover', 'slug' => 'cover', 'template_key' => 'default', 'locale' => 'es']);
        $page->attachMedia($media->id, 'featured_image');
        $this->deleteJson(route('admin.media.destroy', $media))->assertUnprocessable();
    }

    public function test_unused_deletion_removes_variants_and_original(): void
    {
        $media = $this->asset();
        Storage::disk('public')->put('media/variant.webp', 'variant');
        Storage::disk('public')->put('media/original.png', 'original');
        $media->update(['original_path' => 'media/original.png', 'variants' => [['path' => 'media/variant.webp', 'width' => 240]]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->deleteJson(route('admin.media.destroy', $media))->assertOk();
        Storage::disk('public')->assertMissing([$media->path, 'media/variant.webp', 'media/original.png']);
        $this->assertModelMissing($media);
    }

    public function test_restorable_versions_keep_media_after_current_content_unlinks_it(): void
    {
        $media = $this->asset();
        $page = Page::create(['title' => 'History', 'slug' => 'history', 'template_key' => 'home', 'status' => 'draft', 'locale' => 'es', 'content_json' => ['sections' => ['hero' => ['image_id' => $media->id]]]]);
        $page->update(['content_json' => ['sections' => ['hero' => ['image_id' => null]]]]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->deleteJson(route('admin.media.destroy', $media))->assertUnprocessable();
        Storage::disk('public')->assertExists($media->path);
    }

    private function asset(): Media
    {
        Storage::fake('public');
        Storage::disk('public')->put('media/asset.svg', '<svg/>');

        return Media::create(['filename' => 'asset.svg', 'original_filename' => 'asset.svg', 'path' => 'media/asset.svg', 'mime_type' => 'image/svg+xml', 'extension' => 'svg', 'size' => 6, 'disk' => 'public']);
    }
}
