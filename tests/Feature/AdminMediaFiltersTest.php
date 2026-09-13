<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMediaFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function createMedia(string $originalFilename, string $createdAt): Media
    {
        $media = Media::create([
            'filename' => $originalFilename,
            'original_filename' => $originalFilename,
            'path' => 'media/' . $originalFilename,
            'disk' => 'public',
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'size' => 1024,
        ]);

        // created_at is guarded, so set the upload date directly for the filter under test.
        Media::withoutTimestamps(fn () => $media->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save());

        return $media;
    }

    public function test_month_filter_limits_results_to_that_month(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createMedia('april.webp', '2026-04-15 10:00:00');
        $this->createMedia('march.webp', '2026-03-31 23:59:59');
        $this->createMedia('may.webp', '2026-05-01 00:00:00');

        $response = $this->actingAs($admin)->get(route('admin.media.index', ['month' => '2026-04']));

        $response->assertOk();
        $response->assertSee('april.webp');
        $response->assertDontSee('march.webp');
        $response->assertDontSee('may.webp');
    }

    public function test_month_filter_options_list_only_months_with_media(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createMedia('april.webp', '2026-04-15 10:00:00');
        $this->createMedia('also-april.webp', '2026-04-20 10:00:00');
        $this->createMedia('march.webp', '2026-03-02 10:00:00');

        $response = $this->actingAs($admin)->get(route('admin.media.index'));

        $response->assertOk();
        $months = $response->viewData('availableMonths');

        $this->assertSame(['2026-04', '2026-03'], array_column($months, 'value'));
    }

    public function test_invalid_month_filter_is_ignored(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createMedia('april.webp', '2026-04-15 10:00:00');

        $response = $this->actingAs($admin)->get(route('admin.media.index', ['month' => 'not-a-month']));

        $response->assertOk();
        $response->assertSee('april.webp');
    }

    public function test_empty_result_from_filters_shows_the_no_results_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createMedia('april.webp', '2026-04-15 10:00:00');

        $response = $this->actingAs($admin)->get(route('admin.media.index', ['month' => '2026-03']));

        $response->assertOk();
        $response->assertSee(__('admin.no_media_results'));
        $response->assertSee(__('admin.clear_filters'));
        $response->assertDontSee(__('admin.no_media_yet'));
    }

    public function test_empty_library_without_filters_shows_the_upload_prompt(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.media.index'));

        $response->assertOk();
        $response->assertSee(__('admin.no_media_yet'));
        $response->assertDontSee(__('admin.no_media_results'));
    }

    public function test_month_filter_combines_with_type_and_search(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->createMedia('report-april.webp', '2026-04-15 10:00:00');
        $this->createMedia('other-april.webp', '2026-04-16 10:00:00');
        $this->createMedia('report-march.webp', '2026-03-15 10:00:00');

        $response = $this->actingAs($admin)->get(route('admin.media.index', [
            'month' => '2026-04',
            'type' => 'image',
            'search' => 'report',
        ]));

        $response->assertOk();
        $response->assertSee('report-april.webp');
        $response->assertDontSee('other-april.webp');
        $response->assertDontSee('report-march.webp');
    }
}
