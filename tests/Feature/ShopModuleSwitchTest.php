<?php

namespace Tests\Feature;

use App\Mcp\ToolRegistry;
use App\Models\Locale;
use App\Models\Page;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings\SettingOperations;
use App\Services\Shop\ShopModuleOperations;
use App\Support\ShopModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Turning the shop on and off from the panel: Settings > Shop, the config limit, MCP and route:cache (docs/shop/catalog.md). */
class ShopModuleSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
    }

    public function test_a_fresh_site_has_the_shop_tables_and_the_switch_off(): void
    {
        $this->assertTrue(Schema::hasTable('products'));
        $this->assertTrue(Schema::hasTable('product_variants'));
        $this->assertSame('off', Setting::where('key', ShopModule::SETTING)->value('value'));
        $this->assertSame('checkout', config('cms.shop.mode'));
        $this->assertSame('off', app(ShopModule::class)->mode());
    }

    public function test_administrator_turns_the_catalog_on_after_confirming(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $this->get(route('admin.settings.index'))->assertOk()->assertSee(route('admin.settings.shop'), false);
        $this->get(route('admin.settings.shop'))->assertOk()
            ->assertSee('data-shop-module="off"', false)
            ->assertSee(__('shop.module_mode_catalog'))
            ->assertSee(__('shop.module_coming_soon'))
            ->assertDontSee('data-shop-module-confirm', false);

        // Choosing a mode only opens the confirmation step.
        $this->get(route('admin.settings.shop', ['mode' => 'catalog']))->assertOk()
            ->assertSee('data-shop-module-confirm="catalog"', false)
            ->assertSee(__('shop.module_confirm_catalog'));
        $this->assertFalse(app(ShopModule::class)->catalog());

        $this->put(route('admin.settings.shop.update'), ['mode' => 'catalog', 'confirm' => '1'])
            ->assertRedirect(route('admin.settings.shop'))->assertSessionHasNoErrors();

        $this->assertSame('catalog', app(ShopModule::class)->mode());
        $this->assertSame(Product::class, config('cms.collections.products.model'));
        $this->get('/admin/products')->assertOk();
        $this->get('/productos')->assertOk();
        $this->assertContains('products_list', array_column(ToolRegistry::all(), 'name'));
    }

    public function test_switching_without_confirmation_changes_nothing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->from(route('admin.settings.shop'))->put(route('admin.settings.shop.update'), ['mode' => 'catalog'])
            ->assertRedirect(route('admin.settings.shop'))->assertSessionHasErrors('confirm');

        $this->assertSame('off', app(ShopModule::class)->mode());
    }

    public function test_editors_cannot_see_or_switch_the_shop(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor);

        $this->get(route('admin.settings.shop'))->assertForbidden();
        $this->put(route('admin.settings.shop.update'), ['mode' => 'catalog', 'confirm' => '1'])->assertForbidden();
        $this->assertSame('off', app(ShopModule::class)->mode());

        try {
            app(ShopModuleOperations::class)->setMode($editor, 'catalog');
            $this->fail('An editor switched the shop module.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_the_config_limit_wins_over_the_setting(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Stored above the limit: the limit is the effective mode.
        $this->useShop('checkout', 'catalog');
        $this->assertSame('catalog', app(ShopModule::class)->mode());
        $this->useShop('catalog', 'off');
        $this->assertSame('off', app(ShopModule::class)->mode());
        $this->assertArrayNotHasKey('products', config('cms.collections'));
        $this->get('/productos')->assertNotFound();

        // The operation refuses anything the limit does not allow, and checkout until it is delivered.
        foreach (['catalog' => 'off', 'checkout' => 'catalog', 'tienda' => 'checkout'] as $mode => $limit) {
            config(['cms.shop.mode' => $limit]);
            try {
                app(ShopModuleOperations::class)->setMode($admin, $mode);
                $this->fail("Mode {$mode} was stored under limit {$limit}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('mode', $exception->errors());
            }
        }
        config(['cms.shop.mode' => 'checkout']);
        $this->actingAs($admin)->put(route('admin.settings.shop.update'), ['mode' => 'checkout', 'confirm' => '1'])
            ->assertSessionHasErrors('mode');
        $this->assertSame('catalog', Setting::where('key', ShopModule::SETTING)->value('value'));

        // The panel only offers what the limit allows; with the limit off there is nothing to choose.
        config(['cms.shop.mode' => 'catalog']);
        $this->get(route('admin.settings.shop'))->assertOk()
            ->assertSee(__('shop.module_mode_catalog_help'))
            ->assertDontSee(__('shop.module_mode_checkout_help'));
        config(['cms.shop.mode' => 'off']);
        $this->get(route('admin.settings.shop'))->assertOk()->assertSee(__('shop.module_disabled_by_config'));
        $this->get(route('admin.settings.index'))->assertOk()->assertDontSee(route('admin.settings.shop'), false);
    }

    public function test_invalid_stored_mode_throws_a_logic_exception(): void
    {
        Setting::where('key', ShopModule::SETTING)->update(['value' => 'tienda']);
        Setting::clearCache();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Invalid shop_mode setting');
        app(ShopModule::class)->mode();
    }

    public function test_turning_the_shop_off_keeps_products_and_turning_it_on_shows_them_again(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ops = app(ShopModuleOperations::class);
        $ops->setMode($admin, 'catalog');
        Product::create(['title' => 'Mate', 'slug' => 'mate', 'locale' => 'es', 'status' => 'published', 'published_at' => now()->subHour()]);
        $this->get('/productos/mate')->assertOk()->assertSee('Mate');

        $this->actingAs($admin)->get(route('admin.settings.shop', ['mode' => 'off']))->assertOk()
            ->assertSee(__('shop.module_confirm_off'))
            ->assertSee(trans_choice('shop.module_confirm_off_kept', 1, ['count' => 1]));
        $this->put(route('admin.settings.shop.update'), ['mode' => 'off', 'confirm' => '1'])->assertRedirect();

        $this->assertSame(1, Product::count());
        $this->get('/productos/mate')->assertNotFound();
        $this->get('/admin/products')->assertNotFound();
        $this->assertNotContains('products_list', array_column(ToolRegistry::all(), 'name'));

        // Off, the prefix is an ordinary page slug, served by the page route (admin bar included).
        Page::create(['title' => 'Nuestros productos', 'slug' => 'productos', 'locale' => 'es', 'template_key' => 'default', 'content_json' => ['sections' => ['content' => ['heading' => 'Nuestros productos']]], 'status' => 'published', 'published_at' => now()]);
        $this->get('/productos')->assertOk()->assertSee('Nuestros productos');

        $ops->setMode($admin, 'catalog');
        $this->get('/productos/mate')->assertOk()->assertSee('Mate');
        $this->get('/productos')->assertOk()->assertSee('Mate');
    }

    public function test_without_the_settings_table_the_shop_is_off(): void
    {
        $this->useShop('catalog');
        $this->assertTrue(app(ShopModule::class)->catalog());

        Schema::drop('setting_translations');
        Schema::drop('settings');
        Setting::clearCache();

        $this->assertSame('off', app(ShopModule::class)->mode());
        $this->assertFalse(app(ShopModule::class)->catalog());
    }

    public function test_mcp_settings_update_and_reset_follow_the_same_rules(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $settings = app(SettingOperations::class);
        $this->assertContains(ShopModule::SETTING, config('cms.mcp.settings_writable_keys'));

        $settings->update($admin, [ShopModule::SETTING => 'catalog']);
        $this->assertSame('catalog', app(ShopModule::class)->mode());
        $this->assertContains('products_list', array_column(ToolRegistry::all(), 'name'));

        config(['cms.shop.mode' => 'off']);
        try {
            $settings->update($admin, [ShopModule::SETTING => 'catalog']);
            $this->fail('A shop mode above the config limit was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('mode', $exception->errors());
        }
        config(['cms.shop.mode' => 'checkout']);
        try {
            $settings->update($admin, [ShopModule::SETTING => 'tienda']);
            $this->fail('An unknown shop mode was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settings.shop_mode', $exception->errors());
        }

        // The generic Settings form cannot bypass the limit either.
        config(['cms.shop.mode' => 'off']);
        $this->actingAs($admin)->put(route('admin.settings.update'), ['settings' => [ShopModule::SETTING => 'checkout']])
            ->assertSessionHasErrors('mode');
        config(['cms.shop.mode' => 'checkout']);

        $settings->reset($admin, [ShopModule::SETTING]);
        $this->assertSame('off', app(ShopModule::class)->mode());
        $this->assertNotContains('products_list', array_column(ToolRegistry::all(), 'name'));
    }

    public function test_cached_routes_follow_the_mode_without_a_rebuild(): void
    {
        // Relative to the base path (Laravel only treats `/` and `\` as absolute prefixes), away from bootstrap/cache.
        $cache = 'storage/framework/cache/ruweb-routes-'.bin2hex(random_bytes(6)).'.php';
        try {
            // Compiled by its own process, the way a deploy runs it, on an empty database: the shop reads as off
            // there, and the compiled routes do not depend on the mode.
            $result = Process::path(base_path())
                ->env(['APP_ROUTES_CACHE' => $cache, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'])
                ->run([PHP_BINARY, 'artisan', 'route:cache']);
            $this->assertTrue($result->successful(), $result->errorOutput().$result->output());
            $this->app['router']->setRoutes(new RouteCollection);
            require base_path($cache);
            $this->assertInstanceOf(CompiledRouteCollection::class, $this->app['router']->getRoutes());

            $admin = User::factory()->create(['role' => 'admin']);
            Product::create(['title' => 'Mate', 'slug' => 'mate', 'locale' => 'es', 'status' => 'published', 'published_at' => now()->subHour()]);
            Page::create(['title' => 'Nuestros productos', 'slug' => 'productos', 'locale' => 'es', 'template_key' => 'default', 'content_json' => ['sections' => ['content' => ['heading' => 'Nuestros productos']]], 'status' => 'published', 'published_at' => now()]);

            $this->get('/productos')->assertOk()->assertSee('Nuestros productos');
            $this->actingAs($admin)->get('/admin/products')->assertNotFound();

            $this->put(route('admin.settings.shop.update'), ['mode' => 'catalog', 'confirm' => '1'])->assertRedirect();
            $this->get('/productos')->assertOk()->assertSee('Mate')->assertDontSee('Nuestros productos');
            $this->get('/productos/mate')->assertOk();
            $this->get('/admin/products')->assertOk();
            $this->get('/admin/taxonomies/brand')->assertOk();
            $this->get('/admin/editorial/products/'.Product::firstOrFail()->id)->assertOk();

            $this->put(route('admin.settings.shop.update'), ['mode' => 'off', 'confirm' => '1'])->assertRedirect();
            $this->get('/productos')->assertOk()->assertSee('Nuestros productos');
            $this->get('/admin/products')->assertNotFound();
            $this->get('/admin/taxonomies/brand')->assertNotFound();
            $this->get('/admin/editorial/products/'.Product::firstOrFail()->id)->assertNotFound();
        } finally {
            File::delete(base_path($cache));
        }
    }
}
