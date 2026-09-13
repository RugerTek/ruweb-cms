<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Services\Media\PlaceholderMediaInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlaceholderMediaSeedingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep generated files out of the real uploads directory.
        Storage::fake('public');
    }

    protected function installer(): PlaceholderMediaInstaller
    {
        return app(PlaceholderMediaInstaller::class);
    }

    public function test_every_placeholder_stub_ships_with_the_repository(): void
    {
        foreach (PlaceholderMediaInstaller::PLACEHOLDERS as $key => $spec) {
            $this->assertFileExists(
                PlaceholderMediaInstaller::stubDirectory() . '/' . $spec['file'],
                "Missing stub for placeholder [{$key}]."
            );
        }
    }

    public function test_install_creates_every_placeholder_with_webp_and_variants(): void
    {
        $results = $this->installer()->install();

        $this->assertSame(['created', 'created', 'created'], array_values($results));
        $this->assertSame(3, Media::whereNotNull('placeholder_key')->count());

        foreach (array_keys(PlaceholderMediaInstaller::PLACEHOLDERS) as $key) {
            $media = Media::where('placeholder_key', $key)->first();

            $this->assertSame('webp', $media->extension, "Placeholder [{$key}] was not converted.");
            $this->assertTrue(Storage::disk('public')->exists($media->path));
            $this->assertNotEmpty($media->variants, "Placeholder [{$key}] has no responsive variants.");

            foreach ($media->variants as $variant) {
                $this->assertTrue(
                    Storage::disk('public')->exists($variant['path']),
                    "Missing variant file for placeholder [{$key}]."
                );
            }
        }
    }

    public function test_running_twice_does_not_duplicate_placeholders(): void
    {
        $this->installer()->install();
        $ids = Media::whereNotNull('placeholder_key')->orderBy('id')->pluck('id')->all();

        $results = $this->installer()->install();

        $this->assertSame(['skipped', 'skipped', 'skipped'], array_values($results));
        $this->assertSame(3, Media::whereNotNull('placeholder_key')->count());
        $this->assertSame($ids, Media::whereNotNull('placeholder_key')->orderBy('id')->pluck('id')->all());
    }

    public function test_missing_files_are_restored_without_replacing_the_record(): void
    {
        $this->installer()->install();

        $media = Media::where('placeholder_key', 'square')->firstOrFail();
        $originalId = $media->id;
        Storage::disk('public')->delete($media->path);

        $results = $this->installer()->install();

        $this->assertSame('restored', $results['square']);
        $this->assertSame('skipped', $results['horizontal']);

        $media->refresh();
        $this->assertSame($originalId, $media->id);
        $this->assertTrue(Storage::disk('public')->exists($media->path));
        $this->assertNotEmpty($media->variants);
    }

    public function test_force_rebuilds_placeholders_that_are_already_present(): void
    {
        $this->installer()->install();
        $before = Media::where('placeholder_key', 'horizontal')->firstOrFail();

        $results = $this->installer()->install(force: true);

        $this->assertSame(['regenerated', 'regenerated', 'regenerated'], array_values($results));
        $after = Media::where('placeholder_key', 'horizontal')->firstOrFail();
        $this->assertSame($before->id, $after->id);
        $this->assertNotSame($before->path, $after->path);
        $this->assertFalse(Storage::disk('public')->exists($before->path), 'The superseded file was left behind.');
    }

    public function test_seeded_homepage_fills_every_media_slot_with_the_horizontal_placeholder(): void
    {
        $this->seed(\Database\Seeders\LocaleSeeder::class);
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $this->seed(\Database\Seeders\PlaceholderMediaSeeder::class);
        $this->seed(\Database\Seeders\HomepageSeeder::class);

        $horizontal = Media::where('placeholder_key', 'horizontal')->firstOrFail();
        $page = \App\Models\Page::where('slug', 'inicio')->firstOrFail();

        $this->assertSame($horizontal->id, $page->content_json['meta']['featured_image'] ?? null);
        $this->assertSame($horizontal->id, $page->sections()['hero']['image_id'] ?? null);
        $this->assertSame($horizontal->id, $page->featuredImage()?->id);
    }

    public function test_homepage_seeder_does_not_duplicate_the_featured_image_attachment(): void
    {
        $this->seed(\Database\Seeders\LocaleSeeder::class);
        $this->seed(\Database\Seeders\SettingsSeeder::class);
        $this->seed(\Database\Seeders\PlaceholderMediaSeeder::class);
        $this->seed(\Database\Seeders\HomepageSeeder::class);
        $this->seed(\Database\Seeders\HomepageSeeder::class);

        $page = \App\Models\Page::where('slug', 'inicio')->firstOrFail();

        $this->assertCount(1, $page->mediaByCollection('featured_image')->get());
    }

    public function test_artisan_command_seeds_the_placeholders(): void
    {
        $this->artisan('cms:placeholders')->assertSuccessful();

        $this->assertSame(3, Media::whereNotNull('placeholder_key')->count());
    }

    public function test_placeholders_show_up_in_the_admin_media_library(): void
    {
        $this->installer()->install();
        $admin = \App\Models\User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.media.index'));

        $response->assertOk();
        $response->assertSee('placeholder-horizontal.png');
        $response->assertSee('placeholder-square.png');
        $response->assertSee('placeholder-vertical.png');
    }
}
