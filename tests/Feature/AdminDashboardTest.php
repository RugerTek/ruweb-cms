<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('adminProfiles')]
    public function test_dashboard_shows_editorial_counts_and_translation_coverage(string $profile): void
    {
        $this->useAdminProfile($profile);
        $this->seedLocales();
        $admin = User::factory()->create(['role' => 'admin']);

        $basePage = Page::create([
            'locale' => 'es',
            'title' => 'Inicio',
            'slug' => 'inicio',
            'template_key' => 'default',
            'content_json' => ['meta' => ['title' => 'Inicio SEO']],
            'status' => 'published',
            'published_at' => now(),
        ]);
        Page::create([
            'locale' => 'en',
            'translation_group_id' => $basePage->translation_group_id,
            'title' => 'Home',
            'slug' => 'home',
            'template_key' => 'default',
            'status' => 'published',
            'published_at' => now(),
        ]);
        Page::create([
            'locale' => 'fr',
            'translation_group_id' => $basePage->translation_group_id,
            'title' => 'Accueil',
            'slug' => 'accueil',
            'template_key' => 'default',
            'status' => 'published',
            'published_at' => now(),
        ]);
        Page::create([
            'locale' => 'es',
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'content_json' => ['meta' => ['description' => 'Sin SEO title']],
            'status' => 'published',
            'published_at' => now(),
        ]);

        $basePost = Post::create([
            'locale' => 'es',
            'title' => 'Novedades',
            'slug' => 'novedades',
            'meta_json' => ['title' => 'Novedades SEO', 'description' => 'Desc'],
            'status' => 'published',
            'published_at' => now(),
        ]);
        Post::create([
            'locale' => 'en',
            'translation_group_id' => $basePost->translation_group_id,
            'title' => 'Updates',
            'slug' => 'updates',
            'status' => 'published',
            'published_at' => now(),
        ]);
        Post::create([
            'locale' => 'es',
            'title' => 'Sin SEO',
            'slug' => 'sin-seo',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Páginas/posts sin foto destacada');
        $response->assertSee('Páginas/posts sin SEO title');
        $response->assertSee('Páginas/posts sin SEO description');
        $response->assertSee('Traducciones pendientes');
        $response->assertSee('Cobertura de traducciones');
        $response->assertViewHas('pendingTranslationsCount', 5);
        $response->assertViewHas('hasSecondaryPublicLocales', true);
    }

    #[DataProvider('adminProfiles')]
    public function test_dashboard_shows_pending_tasks_and_hides_completed_by_default(string $profile): void
    {
        $this->useAdminProfile($profile);
        $this->seedLocales();
        $admin = User::factory()->create(['role' => 'admin']);

        Setting::updateOrCreate(['key' => 'google_tag_id'], ['value' => 'G-TEST123', 'type' => 'string', 'group' => 'analytics']);
        Setting::updateOrCreate(['key' => 'meta_pixel_id'], ['value' => null, 'type' => 'string', 'group' => 'analytics']);
        Setting::updateOrCreate(['key' => 'search_console_verification_token'], ['value' => null, 'type' => 'string', 'group' => 'analytics']);
        Setting::updateOrCreate(['key' => 'mail_enabled'], ['value' => '0', 'type' => 'boolean', 'group' => 'email']);
        Setting::clearCache();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertSee('Configurar favicon');
        $response->assertSee('Configurar logo del sitio');
        $response->assertSee('Configurar imagen para compartir por defecto');
        $response->assertSee('Personalizar URL de login');
        $response->assertSee('Configurar Meta Pixel');
        $response->assertSee('Configurar Search Console');
        $response->assertSee('Configurar envío de email');
        $response->assertDontSee('Completadas');
        $response->assertDontSee('Configurar Google Tag ID');

        $favicon = Media::create([
            'filename' => 'favicon.png',
            'original_filename' => 'favicon.png',
            'path' => 'media/tests/favicon.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 1024,
            'disk' => 'public',
            'uploaded_by' => $admin->id,
        ]);
        $logo = Media::create([
            'filename' => 'logo.png',
            'original_filename' => 'logo.png',
            'path' => 'media/tests/logo.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 2048,
            'disk' => 'public',
            'uploaded_by' => $admin->id,
        ]);
        $social = Media::create([
            'filename' => 'social.png',
            'original_filename' => 'social.png',
            'path' => 'media/tests/social.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => 4096,
            'disk' => 'public',
            'uploaded_by' => $admin->id,
        ]);

        Setting::updateOrCreate(['key' => 'site_favicon'], ['value' => (string) $favicon->id, 'type' => 'media', 'group' => 'general']);
        Setting::updateOrCreate(['key' => 'site_logo'], ['value' => (string) $logo->id, 'type' => 'media', 'group' => 'general']);
        Setting::updateOrCreate(['key' => 'default_social_image'], ['value' => (string) $social->id, 'type' => 'media', 'group' => 'general']);
        Setting::updateOrCreate(['key' => 'admin_login_path'], ['value' => 'panel-seguro', 'type' => 'string', 'group' => 'admin']);
        Setting::clearCache();

        $response = $this->actingAs($admin)->get(route('admin.dashboard', ['show_completed' => 1]), [
            'SERVER_PROTOCOL' => 'HTTP/2.0',
        ]);

        $response->assertOk();
        $response->assertSee('Completadas');
        $response->assertSee('Configurar Google Tag ID');
        $response->assertSee('Configurar favicon');
        $response->assertSee('Configurar logo del sitio');
        $response->assertSee('Configurar imagen para compartir por defecto');
        $response->assertSee('Personalizar URL de login');
        $response->assertSee('Habilitar HTTP/2');
    }

    #[DataProvider('adminProfiles')]
    public function test_dashboard_hides_translation_widgets_for_monolingual_sites(string $profile): void
    {
        $this->useAdminProfile($profile);
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertOk();
        $response->assertDontSee('Traducciones pendientes');
        $response->assertDontSee('Cobertura de traducciones');
        $response->assertSee('Sin idiomas públicos extra');
    }

    private function seedLocales(): void
    {
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        Locale::create(['code' => 'fr', 'name' => 'Français', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 2]);
    }
}
