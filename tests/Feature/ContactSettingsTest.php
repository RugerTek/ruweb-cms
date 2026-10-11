<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\BusinessContact;
use App\Support\SocialNetworks;
use Database\Seeders\HomepageSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Settings > Contacto and how the public layout consumes it (docs/admin/layout.md). */
class ContactSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
    }

    #[DataProvider('adminProfiles')]
    public function test_contact_tab_renders_fields_and_social_repeater(string $profile): void
    {
        $this->useAdminProfile($profile);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'contact']));

        $response->assertOk();
        $response->assertSee('data-group="contact"', false);
        $response->assertSee('name="settings[contact_phone]"', false);
        $response->assertSee('Teléfono');
        $response->assertSee('Redes sociales');
        $response->assertDontSee('Contact Phone');
        $response->assertSee('name="settings[contact_location][lat]"', false);
        $response->assertSee('data-map-picker', false);
        $response->assertSee('data-repeater-key="contact_social_links"', false);
        $response->assertSee('Agregar red social');

        foreach (SocialNetworks::all() as $definition) {
            $response->assertSee($definition['label']);
        }
    }

    #[DataProvider('adminProfiles')]
    public function test_admin_saves_contact_data_and_social_links_in_order(string $profile): void
    {
        $this->useAdminProfile($profile);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->put(route('admin.settings.update'), [
            'settings' => [
                'contact_phone' => '+595 21 123 456',
                'contact_whatsapp' => '595981123456',
                'contact_email' => 'hola@negocio.com',
                'contact_address' => "Av. Principal 123\nAsunción",
                'contact_location' => ['lat' => '-25.2961824', 'lng' => '-57.6359'],
                'contact_social_links' => [
                    ['network' => 'instagram', 'url' => 'https://www.instagram.com/negocio'],
                    ['network' => 'facebook', 'url' => ''],
                    ['network' => 'tiktok', 'url' => 'https://www.tiktok.com/@negocio'],
                ],
            ],
        ]);

        $response->assertRedirect(route('admin.settings.index'));
        $response->assertSessionHasNoErrors();

        $this->assertSame('+595 21 123 456', BusinessContact::phone());
        $this->assertSame('tel:+59521123456', BusinessContact::phoneHref());
        $this->assertSame('https://wa.me/595981123456', BusinessContact::whatsappHref());
        $this->assertSame('mailto:hola@negocio.com', BusinessContact::emailHref());
        $this->assertSame(['lat' => -25.2961824, 'lng' => -57.6359], BusinessContact::coordinates());
        $this->assertStringContainsString('-25.2961824,-57.6359', BusinessContact::mapsHref());

        $links = BusinessContact::socialLinks();
        $this->assertCount(2, $links, 'rows without URL are dropped');
        $this->assertSame(['instagram', 'tiktok'], array_column($links, 'network'));
        $this->assertSame('Instagram', $links[0]['label']);
        $this->assertNotEmpty($links[0]['icon']);

        $this->assertDatabaseHas('settings', [
            'key' => 'contact_social_links',
            'value' => '[{"network":"instagram","url":"https://www.instagram.com/negocio"},{"network":"tiktok","url":"https://www.tiktok.com/@negocio"}]',
        ]);
    }

    #[DataProvider('adminProfiles')]
    public function test_contact_validation_rejects_unknown_network_bad_url_and_bad_coordinates(string $profile): void
    {
        $this->useAdminProfile($profile);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->from(route('admin.settings.index'))->put(route('admin.settings.update'), [
            'settings' => [
                'contact_email' => 'no-es-un-email',
                'contact_location' => ['lat' => '123', 'lng' => '10'],
                'contact_social_links' => [
                    ['network' => 'myspace', 'url' => 'https://myspace.com/x'],
                    ['network' => 'facebook', 'url' => 'javascript:alert(1)'],
                ],
            ],
        ]);

        $response->assertSessionHasErrors([
            'settings.contact_email',
            'settings.contact_location.lat',
            'settings.contact_social_links.0.network',
            'settings.contact_social_links.1.url',
        ]);
        $this->assertSame([], BusinessContact::socialLinks());
    }

    public function test_public_footer_renders_contact_socials_menu_and_copyright(): void
    {
        $this->seed(HomepageSeeder::class);
        $this->seed(MenuSeeder::class);

        $footer = Menu::where('location', 'footer')->firstOrFail();
        MenuItem::create(['menu_id' => $footer->id, 'label' => 'Aviso legal', 'type' => 'custom_link', 'url' => '/aviso-legal', 'target' => '_self', 'order' => 0]);

        Setting::set('contact_phone', '+595 21 123 456');
        Setting::set('contact_email', 'hola@negocio.com');
        Setting::set('contact_address', 'Av. Principal 123');
        Setting::set('contact_social_links', [['network' => 'youtube', 'url' => 'https://www.youtube.com/@negocio']]);
        Setting::set('footer_tagline', '<p>Hacemos sitios <strong>rápidos</strong>.</p>');
        Setting::set('footer_copyright', '© {year} {site}');
        Setting::set('footer_credit_enabled', '1');
        Setting::set('footer_credit', '<p>Desarrollado por <a href="https://ruweb.dev" target="_blank">Rüweb</a></p>');
        Setting::set('header_cta_enabled', '1');
        Setting::set('header_cta_label', 'Pedir presupuesto');
        Setting::set('header_cta_url', '/contacto');
        Setting::set('header_cta_new_tab', '1');
        Setting::clearCache();

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('href="tel:+59521123456"', false);
        $response->assertSee('href="mailto:hola@negocio.com"', false);
        $response->assertSee('Av. Principal 123');
        $response->assertSee('Ver en el mapa');
        $response->assertSee('aria-label="YouTube"', false);
        $response->assertSee('https://www.youtube.com/@negocio');
        $response->assertSee('Aviso legal');
        $response->assertSee('Hacemos sitios <strong>rápidos</strong>.', false);
        $response->assertSee('© '.date('Y').' '.Setting::get('site_name'));
        $response->assertSee('Desarrollado por <a href="https://ruweb.dev" target="_blank" rel="noopener noreferrer">Rüweb</a>', false);
        $response->assertSee('data-header-cta', false);
        $response->assertSee('href="/contacto" data-header-cta', false);
        $response->assertSee('target="_blank" rel="noopener noreferrer"', false);
        $response->assertSee('Pedir presupuesto');
    }

    #[DataProvider('adminProfiles')]
    public function test_public_footer_hides_empty_blocks(string $profile): void
    {
        $this->useAdminProfile($profile);
        $this->seed(HomepageSeeder::class);
        // A configured but disabled CTA / credit must stay hidden.
        Setting::set('header_cta_label', 'Oculto');
        Setting::set('header_cta_url', '/contacto');
        Setting::set('footer_credit', '<p>Oculto también</p>');
        Setting::clearCache();

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertDontSee('Oculto');
        $response->assertDontSee('Seguinos');
        $response->assertDontSee('data-social-links', false);
        $response->assertDontSee('data-header-cta', false);
        $response->assertDontSee('href="tel:', false);
    }

    public function test_social_networks_catalog_ships_icons_for_every_entry(): void
    {
        foreach (SocialNetworks::all() as $key => $definition) {
            $this->assertMatchesRegularExpression('/^[MmZzLlHhVvCcSsQqTtAa0-9 ,.\-]+$/', $definition['icon'], "icon path for {$key}");
            $this->assertStringStartsWith('https://', $definition['placeholder']);
            $this->assertSame($definition['label'], SocialNetworks::label($key));
        }
    }
}
