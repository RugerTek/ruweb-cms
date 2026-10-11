<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('adminProfiles')]
    public function test_admin_settings_show_login_url_field(string $profile): void
    {
        $this->useAdminProfile($profile);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'admin']));

        $response->assertOk();
        $response->assertSee('URL de login');
    }

    #[DataProvider('adminProfiles')]
    public function test_admin_can_customize_login_path(string $profile): void
    {
        $this->useAdminProfile($profile);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->put(route('admin.settings.update'), [
            'settings' => [
                'admin_login_path' => 'acceso-seguro',
            ],
        ]);

        $response->assertRedirect(route('admin.settings.index'));
        $this->assertSame('acceso-seguro', Setting::get('admin_login_path'));

        Auth::logout();
        $this->rebuildRoutes();

        $this->assertSame('acceso-seguro', Route::getRoutes()->getByName('admin.login')->uri());
        $this->get('/acceso-seguro')
            ->assertOk()
            ->assertViewIs('admin.auth.login');
    }

    public function test_admin_login_path_rejects_reserved_segments(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->from(route('admin.settings.index'))->put(route('admin.settings.update'), [
            'settings' => [
                'admin_login_path' => 'admin',
            ],
        ]);

        $response->assertRedirect(route('admin.settings.index'));
        $response->assertSessionHasErrors('settings.admin_login_path');
    }

    public function test_admin_login_path_rejects_reserved_segments_case_insensitively(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->from(route('admin.settings.index'))->put(route('admin.settings.update'), [
            'settings' => [
                'admin_login_path' => 'Admin',
            ],
        ]);

        $response->assertRedirect(route('admin.settings.index'));
        $response->assertSessionHasErrors('settings.admin_login_path');
        $this->assertSame('login', Setting::get('admin_login_path', 'login'));
    }

    public function test_admin_login_path_rejects_base_locale_page_slug_collisions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        Page::create([
            'locale' => 'es',
            'title' => 'Contacto',
            'slug' => 'contacto',
            'template_key' => 'default',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($admin)->from(route('admin.settings.index'))->put(route('admin.settings.update'), [
            'settings' => [
                'admin_login_path' => 'contacto',
            ],
        ]);

        $response->assertRedirect(route('admin.settings.index'));
        $response->assertSessionHasErrors('settings.admin_login_path');
    }
}
