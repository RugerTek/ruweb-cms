<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\HomepageSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCacheRefreshTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('adminProfiles')]
    public function test_admin_can_refresh_application_cache(string $profile): void
    {
        $this->useAdminProfile($profile);
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'password']);
        $this->seed(SettingsSeeder::class);
        $this->withoutMiddleware(PreventRequestForgery::class);

        Setting::set('meta_pixel_id', 'first');
        $this->assertSame('first', Setting::get('meta_pixel_id'));
        Setting::set('meta_pixel_id', 'second');

        Artisan::spy();

        $response = $this->actingAs($admin)->post(route('admin.cache.refresh'));

        $response->assertRedirect();
        $response->assertSessionHas('success', __('admin.cache_refresh_success'));

        Artisan::shouldHaveReceived('call')->with('optimize:clear')->once();
        Artisan::shouldHaveReceived('call')->with('optimize')->once();
        $this->assertSame('second', Setting::get('meta_pixel_id'));
    }

    #[DataProvider('adminProfiles')]
    public function test_editor_cannot_refresh_application_cache(string $profile): void
    {
        $this->useAdminProfile($profile);
        $editor = User::factory()->create(['role' => 'editor', 'password' => 'password']);
        $this->seed(SettingsSeeder::class);
        $this->withoutMiddleware(PreventRequestForgery::class);

        $response = $this->actingAs($editor)->post(route('admin.cache.refresh'));

        $response->assertForbidden();
    }

    #[DataProvider('adminProfiles')]
    public function test_admin_bar_shows_refresh_cache_button_only_for_admins(string $profile): void
    {
        $this->useAdminProfile($profile);
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'password']);
        $editor = User::factory()->create(['role' => 'editor', 'password' => 'password']);
        $this->seed(SettingsSeeder::class);
        $this->seed(HomepageSeeder::class);

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertSee('Refrescar caché');

        $this->actingAs($editor)
            ->get(route('home'))
            ->assertDontSee('Refrescar caché');
    }

    #[DataProvider('adminProfiles')]
    public function test_admin_bar_uses_admin_locale_on_public_pages(string $profile): void
    {
        $this->useAdminProfile($profile);
        $admin = User::factory()->create(['role' => 'admin', 'password' => 'password']);
        $this->seed(SettingsSeeder::class);
        $this->seed(HomepageSeeder::class);

        Setting::set('admin_locale', 'es');
        Setting::clearCache();

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertSee('Refrescar caché')
            ->assertDontSee('Refresh Cache');
    }
}
