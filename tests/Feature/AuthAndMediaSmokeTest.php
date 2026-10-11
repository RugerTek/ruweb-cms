<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthAndMediaSmokeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('adminProfiles')]
    public function test_guest_admin_routes_render_public_404_for_unauthenticated_visitors(string $profile): void
    {
        $this->useAdminProfile($profile);
        $response = $this->get('/admin');

        $response->assertNotFound();
        $response->assertSee('Back to Home');
    }

    public function test_default_login_path_serves_the_login_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertViewIs('admin.auth.login');
    }

    public function test_custom_login_path_serves_the_login_page(): void
    {
        Setting::set('admin_login_path', 'panel-seguro');

        $this->rebuildRoutes();

        $this->assertSame('panel-seguro', Route::getRoutes()->getByName('admin.login')->uri());

        $this->get('/panel-seguro')
            ->assertOk()
            ->assertViewIs('admin.auth.login');
    }

    #[DataProvider('adminProfiles')]
    public function test_admin_can_upload_media_file(string $profile): void
    {
        $this->useAdminProfile($profile);
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('admin.media.store'), [
            'file' => UploadedFile::fake()->image('cover.jpg'),
            'title' => 'Cover',
            'alt' => 'Cover image',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('title', 'Cover');
        $this->assertDatabaseHas('media', [
            'title' => 'Cover',
            'uploaded_by' => $admin->id,
        ]);
    }

    public function test_admin_can_upload_multiple_media_files(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('admin.media.store'), [
            'files' => [
                UploadedFile::fake()->image('cover-a.jpg'),
                UploadedFile::fake()->image('cover-b.jpg'),
            ],
            'title' => 'Bulk upload',
            'alt' => 'Bulk upload image',
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data');
        $this->assertDatabaseCount('media', 2);
    }

    public function test_invalid_file_in_batch_rejects_entire_upload(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('admin.media.store'), [
            'files' => [
                UploadedFile::fake()->image('cover-a.jpg'),
                UploadedFile::fake()->create('notes.exe', 1, 'application/octet-stream'),
            ],
            'title' => 'Bulk upload',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('media', 0);
        $this->assertSame([], Storage::disk('public')->allFiles('media/' . now()->format('Y/m')));
    }
}
