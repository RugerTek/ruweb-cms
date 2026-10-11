<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\ContactPageSeeder;
use Database\Seeders\HomepageSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Header and Footer admin screens plus the public admin bar shortcut (docs/admin/layout.md). */
class AdminLayoutScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useAdminProfile('full');
        $this->seed(SettingsSeeder::class);
        $this->seed(MenuSeeder::class);
    }

    public function test_header_screen_shows_own_fields_and_shortcuts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $headerMenu = Menu::where('location', 'header')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('admin.header.index'));

        $response->assertOk();
        $response->assertSee('name="settings[site_logo]"', false);
        $response->assertSee('name="settings[header_cta_enabled]"', false);
        $response->assertSee('name="settings[header_cta_label]"', false);
        $response->assertSee('data-link-destination', false);
        $response->assertSee('name="settings[header_cta_page]"', false);
        $response->assertSee('name="settings[header_cta_new_tab]"', false);
        $response->assertSee('Mostrar botón en el header');
        $response->assertSee('Abrir en una pestaña nueva');
        $response->assertSee('Personalizado');
        $response->assertSee('Enlaces del header');
        $response->assertSee(route('admin.menus.edit', $headerMenu));
        $response->assertSee(route('admin.settings.index', ['tab' => 'contact']), false);
    }

    public function test_footer_screen_shows_own_fields_and_shortcuts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $footerMenu = Menu::where('location', 'footer')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('admin.footer.index'));

        $response->assertOk();
        $response->assertSee('name="settings[footer_tagline]"', false);
        $response->assertSee('name="settings[footer_copyright]"', false);
        $response->assertSee('name="settings[footer_credit_enabled]"', false);
        $response->assertSee('name="settings[footer_credit]"', false);
        $response->assertSee('data-rich-text="short-description"', false);
        $response->assertSee('Línea de copyright');
        $response->assertSee('Mostrar crédito de desarrollo');
        $response->assertSee(route('admin.menus.edit', $footerMenu));
        $response->assertSee('Redes sociales');
    }

    public function test_header_update_saves_cta_with_internal_page_and_shared_logo(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->seed(ContactPageSeeder::class);
        $contact = Page::where('slug', 'contacto')->firstOrFail();
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

        $response = $this->actingAs($admin)->put(route('admin.header.update'), [
            'settings' => [
                'site_logo' => $logo->id,
                'header_cta_enabled' => '1',
                'header_cta_label' => 'Pedir presupuesto',
                'header_cta_page' => $contact->translation_group_id,
                'header_cta_url' => 'https://ignored.example', // custom value is dropped when a page is selected
                'header_cta_new_tab' => '0',
            ],
        ]);

        $response->assertRedirect(route('admin.header.index'));
        $response->assertSessionHasNoErrors();
        $this->assertTrue(Setting::get('header_cta_enabled'));
        $this->assertSame('Pedir presupuesto', Setting::get('header_cta_label'));
        $this->assertSame($contact->translation_group_id, Setting::get('header_cta_page'));
        $this->assertNull(Setting::get('header_cta_url'));
        $this->assertTrue(Setting::get('site_logo')?->is($logo));

        // The page reference resolves to the page URL on the public site.
        $this->seed(HomepageSeeder::class);
        $this->get(route('home'))->assertSee('href="/contacto" data-header-cta', false);
    }

    public function test_header_update_saves_custom_destination_and_new_tab(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.header.update'), [
            'settings' => [
                'header_cta_enabled' => '1',
                'header_cta_label' => 'WhatsApp',
                'header_cta_page' => 'custom',
                'header_cta_url' => 'https://wa.me/595981123456',
                'header_cta_new_tab' => '1',
            ],
        ])->assertSessionHasNoErrors();

        $this->assertNull(Setting::get('header_cta_page'));
        $this->assertSame('https://wa.me/595981123456', Setting::get('header_cta_url'));
        $this->assertTrue(Setting::get('header_cta_new_tab'));
    }

    public function test_header_cta_requires_a_label_when_enabled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->from(route('admin.header.index'))->put(route('admin.header.update'), [
            'settings' => ['header_cta_enabled' => '1', 'header_cta_label' => '', 'header_cta_page' => 'custom', 'header_cta_url' => '/x'],
        ])->assertSessionHasErrors('settings.header_cta_label');
    }

    public function test_header_update_rejects_unsafe_cta_url(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->from(route('admin.header.index'))->put(route('admin.header.update'), [
            'settings' => ['header_cta_enabled' => '1', 'header_cta_label' => 'X', 'header_cta_page' => 'custom', 'header_cta_url' => 'javascript:alert(1)'],
        ]);

        $response->assertRedirect(route('admin.header.index'));
        $response->assertSessionHasErrors('settings.header_cta_url');
        $this->assertNull(Setting::get('header_cta_url'));
    }

    public function test_footer_update_saves_rich_tagline_copyright_and_sanitised_credit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.footer.update'), [
            'settings' => [
                'footer_tagline' => '<p>Hacemos sitios <em>rápidos</em>.<script>alert(1)</script></p>',
                'footer_copyright' => '© {year} {site}',
                'footer_credit_enabled' => '1',
                'footer_credit' => '<p>Desarrollado por <a href="https://ruweb.dev" target="_blank" onclick="x()">Rüweb</a></p>',
            ],
        ])->assertRedirect(route('admin.footer.index'));

        $this->assertSame('<p>Hacemos sitios <em>rápidos</em>.</p>', Setting::get('footer_tagline'));
        $this->assertSame('© {year} {site}', Setting::get('footer_copyright'));
        $this->assertTrue(Setting::get('footer_credit_enabled'));
        $this->assertSame('<p>Desarrollado por <a href="https://ruweb.dev" target="_blank" rel="noopener noreferrer">Rüweb</a></p>', Setting::get('footer_credit'));
    }

    public function test_footer_blank_rich_text_is_stored_as_null(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('admin.footer.update'), [
            'settings' => ['footer_tagline' => '<p>&nbsp;</p>', 'footer_credit' => ''],
        ])->assertSessionHasNoErrors();

        $this->assertNull(Setting::get('footer_tagline'));
        $this->assertNull(Setting::get('footer_credit'));
    }

    public function test_editors_cannot_open_layout_screens(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)->get(route('admin.header.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.footer.index'))->assertForbidden();
    }

    public function test_sidebar_lists_header_and_footer_for_admins_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertSee(route('admin.header.index'))
            ->assertSee(route('admin.footer.index'))
            // Settings always opens on General, whatever tab was visited last.
            ->assertSee(route('admin.settings.index', ['tab' => 'general']), false);

        $this->actingAs($editor)->get(route('admin.dashboard'))
            ->assertDontSee(route('admin.header.index'))
            ->assertDontSee(route('admin.footer.index'));
    }

    public function test_public_admin_bar_offers_edit_layout_dropdown_to_admins(): void
    {
        $this->seed(HomepageSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($admin)->get(route('home'))
            ->assertSee('Editar layout')
            ->assertSee('Editar header')
            ->assertSee('Editar footer')
            ->assertSee(route('admin.header.index'))
            ->assertSee(route('admin.footer.index'));

        $this->actingAs($editor)->get(route('home'))
            ->assertDontSee('Editar layout');
    }

    public function test_header_and_footer_groups_are_hidden_from_settings_tabs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.settings.index'));

        $response->assertOk();
        $response->assertDontSee('data-group="header"', false);
        $response->assertDontSee('data-group="footer"', false);
        $response->assertSee('data-group="contact"', false);
    }
}
