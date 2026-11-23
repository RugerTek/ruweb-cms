<?php

namespace Tests\Feature;

use App\Console\Commands\CmsInstallCommand;
use App\Mcp\ToolRegistry;
use App\Models\Media;
use App\Models\Menu;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Media\MediaUsage;
use App\Services\Menus\MenuOperations;
use App\Support\AdminCapabilities;
use App\Support\ShopModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

/** The optional shop module as a whole: switch, discovery, installer, menus and media (docs/shop/catalog.md). */
class ShopModuleTest extends TestCase
{
    use RefreshDatabase;

    private ?string $fixture = null;

    private string $originalConfigPath;

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->app->useConfigPath($this->originalConfigPath);
            File::deleteDirectory($this->fixture);
        }
        parent::tearDown();
    }

    public function test_mode_off_exposes_no_shop_routes_menu_tools_or_collection(): void
    {
        $this->useAdminProfile('full');
        $this->useShop('off');
        $admin = User::factory()->create(['role' => 'admin']);

        // The routes are always registered (route:cache) but none of them answers while the module is off.
        $this->get('/productos')->assertNotFound();
        $this->get('/en/productos')->assertRedirect('/productos'); // the localized page catch-all: `en` is not a public language here
        $this->actingAs($admin)->get('/admin/products')->assertNotFound();
        $this->actingAs($admin)->get('/admin/products/create')->assertNotFound();
        $this->actingAs($admin)->get('/admin/taxonomies/brand')->assertNotFound();
        $this->actingAs($admin)->get('/admin/taxonomies/category')->assertOk();
        $this->assertArrayNotHasKey('products', config('cms.collections'));
        $this->assertArrayNotHasKey('product', config('cms.content_schemas'));
        $this->assertSame([], array_values(array_filter(array_column(ToolRegistry::all(), 'name'), fn (string $name) => str_starts_with($name, 'products_'))));

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee('data-sidebar-toggle="shop-group"', false);

        $this->assertSame('off', app(AdminCapabilities::class)->discovery()['modules']['shop']);
    }

    public function test_catalog_mode_registers_public_routes_and_capabilities_report_the_mode(): void
    {
        $this->useShop('catalog');

        $this->assertTrue(Route::has('shop.index'));
        $this->assertTrue(Route::has('localized.shop.show'));
        $this->assertSame(Product::class, config('cms.collections.products.model'));

        foreach (['catalog', 'checkout', 'off'] as $mode) {
            $this->useShop($mode);
            $this->assertSame(0, Artisan::call('cms:capabilities'));
            $this->assertSame($mode, json_decode(Artisan::output(), true)['modules']['shop']);
        }
    }

    public function test_invalid_mode_fails_discovery_with_an_explicit_error(): void
    {
        config(['cms.shop.mode' => 'tienda']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invalid cms.shop.mode');
        app(AdminCapabilities::class)->discovery();
    }

    public function test_installer_shop_option_stores_the_setting_and_leaves_the_config_limit_alone(): void
    {
        $config = "<?php return [\n    'admin_profile' => 'full',\n    'shop' => [\n        'mode' => 'checkout',\n        'path' => 'productos',\n    ],\n];\n";
        $this->useConfigFixture($config);
        User::factory()->create(['email' => 'existing@example.test', 'username' => 'existing', 'role' => 'admin']);
        $tester = $this->installer(successful: true);
        $tester->setInputs(['127.0.0.1', '3306', 'fixture', 'root', 'es', 'existing@example.test']);

        $this->assertSame(0, $tester->execute(['--profile' => 'essential', '--shop' => 'catalog']));

        $persisted = require $this->fixture.'/config/cms.php';
        $this->assertSame('checkout', $persisted['shop']['mode']);
        $this->assertSame('essential', $persisted['admin_profile']);
        $this->assertSame('catalog', Setting::where('key', ShopModule::SETTING)->value('value'));
        $this->assertSame('catalog', app(ShopModule::class)->mode());
        $this->assertSame(Product::class, config('cms.collections.products.model'));
        $this->assertStringContainsString('Shop module: catalog', $tester->getDisplay());
    }

    public function test_installer_rejects_an_invalid_or_over_the_limit_shop_mode_before_any_change(): void
    {
        $this->useConfigFixture("<?php return ['admin_profile' => 'full'];\n");
        $before = file_get_contents($this->fixture.'/config/cms.php');

        $tester = $this->installer(successful: false);
        $this->assertSame(1, $tester->execute(['--profile' => 'full', '--shop' => 'tienda']));
        $this->assertStringContainsString('must be off, catalog or checkout', $tester->getDisplay());

        config(['cms.shop.mode' => 'off']);
        $tester = $this->installer(successful: false);
        $this->assertSame(1, $tester->execute(['--profile' => 'full', '--shop' => 'catalog']));
        $this->assertStringContainsString('allows at most off', $tester->getDisplay());

        config(['cms.shop.mode' => 'checkout']);
        $tester = $this->installer(successful: false);
        $this->assertSame(1, $tester->execute(['--profile' => 'full', '--shop' => 'checkout']));
        $this->assertStringContainsString('checkout is not delivered yet', $tester->getDisplay());

        $this->assertSame($before, file_get_contents($this->fixture.'/config/cms.php'));
        $this->assertSame('off', Setting::where('key', ShopModule::SETTING)->value('value'));
        $this->assertSame(0, User::count());
    }

    public function test_menus_link_products_only_while_the_catalog_is_on(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $ops = app(MenuOperations::class);
        $menu = Menu::create(['name' => 'Header', 'slug' => 'header', 'locale' => 'es']);
        $product = Product::create(['title' => 'Mate', 'slug' => 'mate', 'locale' => 'es']);
        $item = ['id' => 1, 'label' => 'Mate', 'type' => 'product', 'linkable_id' => $product->id, 'target' => '_self', 'order' => 0];

        $this->useShop('catalog');
        $this->assertSame(Product::class, MenuOperations::linkableTypes()['product']);
        $ops->syncItems($actor, $menu, ['items' => [$item]]);
        $stored = $menu->items()->firstOrFail();
        $this->assertSame(Product::class, $stored->linkable_type);
        $this->assertSame($product->id, $stored->linkable_id);

        // Off: the stored item is kept on save (no catalog check), a new product item is refused.
        $this->useShop('off');
        $this->assertArrayNotHasKey('product', MenuOperations::linkableTypes());
        $ops->syncItems($actor, $menu->fresh(), ['items' => [$item]]);
        $this->assertSame([Product::class, $product->id], [$menu->items()->sole()->linkable_type, $menu->items()->sole()->linkable_id]);
        $other = Product::create(['title' => 'Bombilla', 'slug' => 'bombilla', 'locale' => 'es']);
        try {
            $ops->syncItems($actor, $menu->fresh(), ['items' => [$item, ['id' => 2, 'linkable_id' => $other->id, 'order' => 1] + $item]]);
            $this->fail('A new product menu item was saved with the shop module off.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('items.1.type', $exception->errors());
        }
        $this->assertSame([$product->id], $menu->items()->pluck('linkable_id')->all());
    }

    public function test_product_featured_image_gallery_and_rich_text_keep_media_in_use(): void
    {
        $this->useShop('catalog');
        $usage = app(MediaUsage::class);
        [$cover, $photo, $inline] = [$this->asset('cover'), $this->asset('photo'), $this->asset('inline')];
        $product = Product::create(['title' => 'Mate', 'slug' => 'mate', 'locale' => 'es', 'description' => '<p>Detalle</p><img src="'.parse_url($inline->url(), PHP_URL_PATH).'" alt="">']);
        $product->attachMedia($cover->id, 'featured_image');
        $product->attachMedia($photo->id, 'gallery', 1);

        $this->assertSame(['featured_image'], array_column($usage->references($cover), 'field'));
        $this->assertSame(['gallery'], array_column($usage->references($photo), 'field'));
        $this->assertContains(['type' => 'products', 'id' => $product->id, 'label' => 'Mate (es)', 'field' => 'description'], $usage->references($inline));

        // Switching the module off keeps products and their media protected.
        $this->useShop('off');
        $this->assertNotSame([], $usage->references($inline));
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->deleteJson(route('admin.media.destroy', $cover))->assertUnprocessable();
        $this->assertModelExists($cover);
    }

    private function asset(string $name): Media
    {
        Storage::fake('public');
        Storage::disk('public')->put("media/{$name}.svg", '<svg/>');

        return Media::create(['filename' => "{$name}.svg", 'original_filename' => "{$name}.svg", 'path' => "media/{$name}.svg", 'mime_type' => 'image/svg+xml', 'extension' => 'svg', 'size' => 6, 'disk' => 'public']);
    }

    private function useConfigFixture(string $cmsConfig): void
    {
        $this->fixture = sys_get_temp_dir().'/cms-shop-install-'.bin2hex(random_bytes(8));
        $this->originalConfigPath = $this->app->configPath();
        File::makeDirectory($this->fixture.'/config', 0755, true);
        file_put_contents($this->fixture.'/config/cms.php', $cmsConfig);
        $this->app->useConfigPath($this->fixture.'/config');
    }

    /** Same seams as CmsInstallTest, plus `secret()`: hidden console input cannot be fed by CommandTester on Windows. */
    private function installer(bool $successful): CommandTester
    {
        if ($successful) {
            Artisan::shouldReceive('call')->with('migrate', ['--force' => true])->once()->andReturn(0);
            Artisan::shouldReceive('call')->with('db:seed', \Mockery::type('array'))->times(3)->andReturn(0);
            Artisan::shouldReceive('call')->with('storage:link')->zeroOrMoreTimes()->andReturn(0);
            Artisan::shouldReceive('call')->with('cms:placeholders', [], \Mockery::any())->once()->andReturn(0);
            Artisan::shouldReceive('call')->with('config:clear')->once()->andReturn(0);
            Artisan::shouldReceive('call')->with('route:clear')->once()->andReturn(0);
        }
        $command = new class extends CmsInstallCommand
        {
            protected function updateEnv(array $values): void {}

            protected function configureDatabase(string $dbHost, string $dbPort, string $dbName, string $dbUser, string $dbPass): bool
            {
                return true;
            }

            public function secret($question, $fallback = true)
            {
                return null;
            }
        };
        $command->setLaravel($this->app);

        return new CommandTester($command);
    }
}
