<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use App\Services\Media\PlaceholderMediaInstaller;
use Database\Seeders\HomepageSeeder;
use Database\Seeders\LocaleSeeder;
use Database\Seeders\PlaceholderMediaSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HomeLogoCarouselTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->seed(LocaleSeeder::class);
        $this->seed(SettingsSeeder::class);
        $this->seed(PlaceholderMediaSeeder::class);
        $this->seed(HomepageSeeder::class);
    }

    protected function homepage(): Page
    {
        return Page::where('slug', 'inicio')->firstOrFail();
    }

    public function test_seeder_fills_the_carousel_with_four_square_placeholders(): void
    {
        $square = Media::where('placeholder_key', 'square')->firstOrFail();
        $logos = $this->homepage()->sections()['features']['logos'] ?? [];

        $this->assertCount(4, $logos);

        foreach ($logos as $logo) {
            $this->assertSame($square->id, $logo['image_id']);
            $this->assertNotEmpty($logo['label']);
        }
    }

    public function test_public_home_renders_each_logo_more_than_once_so_the_loop_is_seamless(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('logo-marquee-track', false);

        $items = substr_count($response->getContent(), 'logo-marquee-item');
        $this->assertGreaterThanOrEqual(8, $items, 'The track must repeat the logos to loop seamlessly.');

        // The duplicated copies are decorative and must not be announced twice.
        $this->assertGreaterThan(0, substr_count($response->getContent(), 'aria-hidden="true"'));
    }

    public function test_logos_without_an_image_are_skipped(): void
    {
        $page = $this->homepage();
        $content = $page->content_json;
        $content['sections']['features']['logos'] = [
            ['image_id' => Media::where('placeholder_key', 'square')->firstOrFail()->id, 'label' => 'Real'],
            ['image_id' => null, 'label' => 'Sin imagen'],
        ];
        $page->forceFill(['content_json' => $content])->save();

        $response = $this->get('/');

        $response->assertOk();
        $this->assertSame(0, substr_count($response->getContent(), 'Sin imagen'));
    }

    public function test_carousel_is_hidden_when_there_are_no_logos(): void
    {
        $page = $this->homepage();
        $content = $page->content_json;
        $content['sections']['features']['logos'] = [];
        $page->forceFill(['content_json' => $content])->save();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('logo-marquee-track', false);
    }

    public function test_editor_persists_one_entry_per_row_when_logos_are_submitted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->homepage();
        $square = Media::where('placeholder_key', 'square')->firstOrFail();

        $payload = [
            'title' => $page->title,
            'slug' => $page->slug,
            'locale' => $page->locale,
            'template_key' => 'home',
            'status' => 'published',
            'content_json' => [
                'sections' => [
                    'features' => [
                        'is_visible' => 1,
                        'heading' => 'Listo para editar',
                        'logos' => [
                            ['image_id' => (string) $square->id, 'label' => 'Uno'],
                            ['image_id' => (string) $square->id, 'label' => 'Dos'],
                        ],
                    ],
                ],
            ],
        ];

        $this->actingAs($admin)
            ->put(route('admin.pages.update', $page), $payload)
            ->assertRedirect();

        $logos = $this->homepage()->sections()['features']['logos'] ?? [];

        $this->assertCount(2, $logos, 'Each row must persist as a single entry.');
        $this->assertSame('Uno', $logos[0]['label']);
        $this->assertSame((string) $square->id, (string) $logos[0]['image_id']);
        $this->assertSame('Dos', $logos[1]['label']);
    }

    public function test_editor_exposes_the_logo_repeater_for_the_home_template(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.pages.edit', $this->homepage()));

        $response->assertOk();
        $response->assertSee('data-logo-repeater', false);
        $response->assertSee('data-logo-add', false);
        $response->assertSee('data-logo-template', false);
        // Four seeded rows plus the hidden <template> row the Add button clones.
        $this->assertSame(5, substr_count($response->getContent(), 'data-logo-item>'));
    }

    public function test_each_logo_row_is_its_own_collapsed_accordion_with_item_controls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.pages.edit', $this->homepage()));
        $content = $response->getContent();

        $response->assertOk();

        // One nested accordion per item, collapsed by default.
        $this->assertSame(5, substr_count($content, 'data-logo-accordion-toggle'));
        $this->assertSame(5, preg_match_all('/data-logo-accordion-toggle\s+aria-expanded="false"/', $content));

        // Item header controls: ordering plus a delete button that stays reachable.
        $this->assertSame(5, substr_count($content, 'data-logo-move-up'));
        $this->assertSame(5, substr_count($content, 'data-logo-move-down'));
        $this->assertSame(5, substr_count($content, 'data-logo-remove'));
        $this->assertSame(5, substr_count($content, 'data-logo-item-label'));
    }

    public function test_item_headers_use_the_label_with_a_numbered_fallback(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = $this->homepage();
        $content = $page->content_json;
        $content['sections']['features']['logos'] = [
            ['image_id' => Media::where('placeholder_key', 'square')->firstOrFail()->id, 'label' => 'Acme'],
            ['image_id' => Media::where('placeholder_key', 'square')->firstOrFail()->id, 'label' => ''],
        ];
        $page->forceFill(['content_json' => $content])->save();

        $response = $this->actingAs($admin)->get(route('admin.pages.edit', $page));

        $response->assertOk();
        $response->assertSee('Acme');
        $response->assertSee(__('admin.logo_item_fallback_label', ['position' => 2]));
    }

    public function test_editor_ships_the_shared_confirmation_modal_instead_of_a_native_dialog(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.pages.edit', $this->homepage()));

        $response->assertOk();
        $response->assertSee('id="confirm-modal"', false);
        $response->assertSee('confirm-modal-accept', false);
        $response->assertSee('confirm-modal-cancel', false);
    }

    public function test_placeholder_installer_exposes_a_square_placeholder_for_logos(): void
    {
        $this->assertArrayHasKey('square', PlaceholderMediaInstaller::PLACEHOLDERS);
    }
}
