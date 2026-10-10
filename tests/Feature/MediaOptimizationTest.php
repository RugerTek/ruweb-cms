<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_jpg_is_converted_to_webp_and_generates_variants(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available.');
        }

        Storage::fake('public');
        config()->set('cms.image_optimization.keep_original', false);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('admin.media.store'), [
            'file' => UploadedFile::fake()->image('cover.jpg', 2600, 1600),
            'title' => 'Cover',
            'alt' => 'Cover image',
        ]);

        $response->assertCreated();
        $media = Media::query()->latest()->firstOrFail();

        $this->assertSame('webp', $media->extension);
        $this->assertSame('image/webp', $media->mime_type);
        $this->assertNotNull($media->original_size);
        $this->assertSame('jpg', $media->original_extension);
        $this->assertNotNull($media->optimized_at);
        $this->assertNull($media->original_path);
        $this->assertNotNull($media->width);
        $this->assertNotNull($media->height);
        $this->assertTrue($media->hasResponsiveVariants());
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_upload_respects_keep_original_true(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available.');
        }

        Storage::fake('public');
        config()->set('cms.image_optimization.keep_original', true);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('admin.media.store'), [
            'file' => UploadedFile::fake()->image('keep.png', 1200, 900),
            'title' => 'Keep original',
        ]);

        $response->assertCreated();
        $media = Media::query()->latest()->firstOrFail();

        $this->assertSame('webp', $media->extension);
        $this->assertNotNull($media->original_path);
        Storage::disk('public')->assertExists($media->original_path);
    }

    public function test_upload_svg_is_not_converted_or_variant_processed(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('admin.media.store'), [
            'file' => UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path d="M0 0h10v10z"/></svg>'),
            'title' => 'Vector',
        ]);

        $response->assertCreated();
        $media = Media::query()->latest()->firstOrFail();

        $this->assertSame('svg', $media->extension);
        $this->assertNull($media->optimized_at);
        $this->assertNull($media->original_size);
        $this->assertFalse($media->hasResponsiveVariants());
    }

    public function test_upload_gif_keeps_original_format_but_stores_dimensions(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('admin.media.store'), [
            'file' => UploadedFile::fake()->image('animated.gif', 640, 480),
            'title' => 'Animated asset',
        ]);

        $response->assertCreated();
        $media = Media::query()->latest()->firstOrFail();

        $this->assertSame('gif', $media->extension);
        $this->assertNull($media->optimized_at);
        $this->assertNull($media->original_size);
        $this->assertSame(640, $media->width);
        $this->assertSame(480, $media->height);
        $this->assertFalse($media->hasResponsiveVariants());
    }

    public function test_media_convert_webp_dry_run_does_not_change_db_or_files(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available.');
        }

        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        $path = UploadedFile::fake()->image('legacy.jpg', 1400, 900)->store('media/2026/04', 'public');
        $size = Storage::disk('public')->size($path);

        $media = Media::create([
            'filename' => basename($path),
            'original_filename' => 'legacy.jpg',
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size' => $size,
            'disk' => 'public',
            'uploaded_by' => $admin->id,
        ]);

        $this->artisan('media:convert-webp --dry-run')->assertExitCode(0);
        $media->refresh();

        $this->assertSame('jpg', $media->extension);
        $this->assertNull($media->optimized_at);
        Storage::disk('public')->assertExists($path);
    }

    public function test_generate_variants_force_replaces_variants_and_audit_health_runs(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available.');
        }

        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        config()->set('cms.image_optimization.keep_original', false);

        $this->actingAs($admin)->postJson(route('admin.media.store'), [
            'file' => UploadedFile::fake()->image('hero.jpg', 1800, 1200),
            'title' => 'Hero',
        ])->assertCreated();

        $media = Media::query()->latest()->firstOrFail();
        $firstVariants = $media->variants;

        $this->artisan('media:generate-variants --force')->assertExitCode(0);
        $media->refresh();

        $this->assertNotEmpty($media->variants);
        $widths = collect($media->variants)->pluck('width')->all();
        $this->assertSame($widths, array_values(array_unique($widths)));
        $this->assertCount(count($firstVariants), $media->variants);

        $this->artisan('media:audit-health')
            ->expectsOutputToContain('Media Health Summary')
            ->assertExitCode(0);
    }
}
