<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Menus\MenuOperations;
use App\Services\Settings\SettingOperations;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class McpLayoutOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_active' => true, 'is_public' => true]);
        $this->seed(SettingsSeeder::class);
        Setting::clearCache();
    }

    public function test_layout_contact_values_use_canonical_rows_and_typed_sanitization(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $service = app(SettingOperations::class);
        $service->update($actor, ['contact_email' => 'example@example.test', 'contact_location' => ['lat' => -25, 'lng' => -57], 'footer_tagline' => '<p>Hello<script>alert(1)</script></p>']);
        $this->assertSame('example@example.test', Setting::get('contact_email'));
        $this->assertSame(['lat' => -25.0, 'lng' => -57.0], Setting::get('contact_location'));
        $this->assertStringNotContainsString('<script', Setting::get('footer_tagline'));
        $service->update($actor, ['footer_tagline' => '<p>English</p>'], 'en');
        $this->assertSame('<p>English</p>', Setting::getLocalized('footer_tagline', 'en'));
        $this->assertSame(1, Setting::where('key', 'footer_tagline')->count());
        config(['app.url' => 'http://localhost']);
        $service->update($actor, ['header_cta_url' => 'http://localhost/contact']);
        $this->assertSame('/contact', Setting::get('header_cta_url'));
        $this->assertNull(Setting::get('header_cta_page'));

    }

    public function test_batch_failure_and_stale_revision_preserve_every_value(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $service = app(SettingOperations::class);
        $before = Setting::get('site_name');
        try {
            $service->update($actor, ['site_name' => 'Should roll back', 'contact_email' => 'bad'], 'en');
            $this->fail('Invalid email must fail.');
        } catch (ValidationException) {
            $this->assertSame($before, Setting::get('site_name'));
        }
        $revision = $service->fingerprint(Setting::where('key', 'site_name')->first());
        $service->update($actor, ['site_name' => 'New']);
        try {
            $service->update($actor, ['contact_phone' => '123', 'site_name' => 'Stale'], revisions: ['site_name' => $revision]);
            $this->fail('Stale revision must conflict.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('New', Setting::get('site_name'));
            $this->assertNotSame('123', Setting::get('contact_phone'));
        }
    }

    public function test_menus_reject_cross_language_targets_and_cycles_without_loss(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $ops = app(MenuOperations::class);
        $page = Page::create(['locale' => 'es', 'title' => 'Page', 'slug' => 'page', 'template_key' => 'default', 'status' => 'draft']);
        $menu = $ops->create($actor, ['name' => 'English', 'slug' => 'english', 'locale' => 'en']);
        $item = ['id' => 1, 'label' => 'Link', 'type' => 'custom_link', 'url' => '/hello', 'target' => '_self', 'order' => 0];
        $ops->syncItems($actor, $menu, ['items' => [$item]]);
        foreach ([[$item + ['parent_id' => 2], array_replace($item, ['id' => 2, 'parent_id' => 1])], [array_replace($item, ['type' => 'page', 'linkable_id' => $page->id])]] as $bad) {
            try {
                $ops->syncItems($actor, $menu, ['items' => $bad]);
                $this->fail('Invalid menu input must fail.');
            } catch (HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
                $this->assertSame('/hello', $menu->items()->first()->url);
            }
        }
        try {
            $ops->update($actor, $menu, ['locale' => 'es']);
            $this->fail('Language change with items must fail.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('en', $menu->fresh()->locale);
        }
    }

    public function test_menu_revision_conflicts_and_creation_retries_preserve_identity(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $ops = app(MenuOperations::class);
        $input = ['name' => 'Menu', 'slug' => 'menu', 'operation_key' => 'menu-create'];
        $menu = $ops->create($actor, $input);
        $this->assertSame($menu->id, $ops->create($actor, $input)->id);
        $revision = $ops->fingerprint($menu);
        $ops->update($actor, $menu, ['name' => 'Current']);
        try {
            $ops->syncItems($actor, $menu, ['expected_revision' => $revision, 'items' => []]);
            $this->fail('Stale revision must fail.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('Current', $menu->fresh()->name);
        }
    }

    public function test_same_site_cta_urls_are_portable_from_rest_and_rpc(): void
    {
        config(['app.url' => 'http://localhost']);
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->mcpApiKeys()->create(['name' => 'Layout', 'key_hash' => hash('sha256', 'layout-token'), 'scope' => 'full']);
        $this->withHeader('Authorization', 'Bearer layout-token');
        $this->patchJson('/mcp/settings', ['settings' => ['header_cta_url' => 'http://localhost/rest-contact']])->assertOk();
        $this->assertSame('/rest-contact', Setting::get('header_cta_url'));
        $response = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'settings_update', 'arguments' => ['key' => 'header_cta_url', 'value' => 'http://localhost/rpc-contact']]])->assertOk()->assertJsonPath('result.isError', false);
        $this->assertSame('/rpc-contact', Setting::get('header_cta_url'));
        $this->assertNull(Setting::get('header_cta_page'));
    }

    public function test_same_site_cta_urls_are_portable_from_the_full_panel(): void
    {
        $this->useAdminProfile('full');
        config(['app.url' => 'http://localhost']);
        $actor = User::factory()->create(['role' => 'admin']);

        $this->actingAs($actor)->put(route('admin.header.update'), ['settings' => ['header_cta_url' => 'http://localhost/panel-contact']])->assertRedirect(route('admin.header.index'));
        $this->assertSame('/panel-contact', Setting::get('header_cta_url'));
        $this->assertNull(Setting::get('header_cta_page'));
    }

    public function test_malformed_menu_revision_has_recoverable_field_error_on_rest_and_rpc(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $actor->mcpApiKeys()->create(['name' => 'Menu validation', 'key_hash' => hash('sha256', 'menu-validation-token'), 'scope' => 'full']);
        $this->withHeader('Authorization', 'Bearer menu-validation-token');
        $menu = app(MenuOperations::class)->create($actor, ['name' => 'Original', 'slug' => 'original']);
        $this->patchJson('/mcp/menus/'.$menu->id, ['name' => 'Rejected', 'expected_revision' => []])->assertUnprocessable()->assertJsonValidationErrors('expected_revision');
        $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'menus_update', 'arguments' => ['menu_id' => $menu->id, 'name' => 'Rejected', 'expected_revision' => []]]])->assertOk()->assertJsonPath('error.code', -32602)->assertJsonStructure(['error' => ['data' => ['expected_revision']]]);
        $this->assertSame('Original', $menu->fresh()->name);
    }
}
