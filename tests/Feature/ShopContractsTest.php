<?php

namespace Tests\Feature;

use App\Casts\MoneyCast;
use App\Helpers\MoneyHelper;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Editorial\EditorialActor;
use App\Support\ShopModule;
use App\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Shared contracts of the optional shop module (PLAN.md, stage 1 P0). */
class ShopContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_formats_pyg_without_decimals_and_parses_typed_amounts(): void
    {
        $money = Money::fromInt(1234567);

        $this->assertSame('PYG', $money->currency);
        $this->assertSame('Gs. 1.234.567', $money->format('es'));
        // The currency decides the format, not the interface language.
        $this->assertSame('Gs. 1.234.567', $money->format('en'));
        $this->assertSame('Gs. -5.000', Money::fromInt(-5000)->format('en'));
        $this->assertSame(1500, Money::fromInt(1000)->add(Money::fromInt(500))->amount);
        $this->assertSame('Gs. 150.000', MoneyHelper::format(150000, null, 'es'));
        $this->assertSame('', MoneyHelper::format(null));
        $this->assertSame(150000, MoneyHelper::parseInput('150.000'));
        $this->assertSame(0, MoneyHelper::parseInput('Gs.'));

        $this->expectException(InvalidArgumentException::class);
        Money::fromInt(1)->add(new Money(1, 'USD'));
    }

    public function test_money_cast_stores_integers_and_reads_money_in_the_configured_currency(): void
    {
        $product = Product::create(['title' => 'Mate', 'slug' => 'mate', 'price_amount' => 150000, 'compare_at_price_amount' => Money::fromInt(180000)]);

        $this->assertSame(150000, (int) DB::table('products')->where('id', $product->id)->value('price_amount'));
        $fresh = $product->fresh();
        $this->assertInstanceOf(Money::class, $fresh->price_amount);
        $this->assertTrue($fresh->price_amount->equals(Money::fromInt(150000)));
        $this->assertSame(180000, $fresh->compare_at_price_amount->amount);

        $withoutPrice = Product::create(['title' => 'Consultar', 'slug' => 'consultar']);
        $this->assertNull($withoutPrice->fresh()->price_amount);

        $this->expectException(InvalidArgumentException::class);
        (new MoneyCast)->set($product, 'price_amount', '12.5', []);
    }

    public function test_products_and_variants_live_in_the_module_tables(): void
    {
        $this->assertTrue(Schema::hasTable('products'));
        $this->assertTrue(Schema::hasTable('product_variants'));
        $this->assertTrue(Schema::hasColumns('products', ['locale', 'translation_group_id', 'translation_status', 'source_fingerprint', 'source_field_hashes', 'sku', 'title', 'expires_at', 'created_by', 'updated_by']));

        $product = Product::create([
            'title' => 'Remera',
            'slug' => 'remera',
            'sku' => 'REM',
            'description' => '<p>Algodón</p><script>alert(1)</script>',
            'has_variants' => true,
            'status' => 'draft',
        ]);
        $product->variants()->create(['name' => 'Talle L', 'sku' => 'REM-L', 'price_amount' => 95000, 'attributes_json' => ['size' => 'L'], 'order' => 2]);
        $product->variants()->create(['name' => 'Talle S', 'sku' => 'REM-S', 'price_amount' => 90000, 'attributes_json' => ['size' => 'S'], 'order' => 1]);
        $product->variants()->create(['name' => 'Talle XL', 'sku' => 'REM-XL', 'order' => 3]);

        $product = $product->fresh();
        $this->assertSame('es', $product->locale);
        $this->assertNotEmpty($product->translation_group_id);
        $this->assertStringNotContainsString('<script', (string) $product->description);
        $this->assertSame(['Talle S', 'Talle L', 'Talle XL'], $product->variants->pluck('name')->all());
        $this->assertInstanceOf(ProductVariant::class, $product->variants->first());
        $this->assertSame('L', $product->variants[1]->attributes_json['size']);
        $this->assertSame(90000, $product->displayPrice()->amount);
        $this->assertTrue($product->variants->first()->product->is($product));
        $this->assertSame('/productos/remera', $product->url());
        $this->assertSame('Remera', $product->seoTitleFallback());

        // Products share the editorial history and revision ledger contracts of posts.
        $product->update(['title' => 'Remera lisa']);
        $this->assertTrue(DB::table('editorial_versions')->where('content_type', 'products')->where('content_id', $product->id)->exists());
        $this->assertTrue(DB::table('content_revisions')->where('entity_type', Product::class)->where('entity_id', $product->id)->exists());

        $product->delete();
        $this->assertSame(0, ProductVariant::count());
    }

    public function test_editor_cannot_publish_a_product_directly(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        try {
            EditorialActor::run($editor, fn () => Product::create(['title' => 'Publicado', 'slug' => 'publicado', 'status' => 'published', 'published_at' => now()]));
            $this->fail('An editor published a product.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, Product::count());
    }

    public function test_mode_off_registers_nothing_and_catalog_registers_the_collection(): void
    {
        $this->useShop('off');
        $this->assertSame('off', app(ShopModule::class)->mode());
        $this->assertFalse(app(ShopModule::class)->catalog());
        $this->assertArrayNotHasKey('products', config('cms.collections'));
        $this->assertArrayNotHasKey('product', config('cms.content_schemas'));
        $this->assertNotContains('brand', config('cms.taxonomy_types'));

        $this->useShop('catalog');
        $this->assertTrue(app(ShopModule::class)->catalog());
        $this->assertFalse(app(ShopModule::class)->checkout());
        $this->assertSame(Product::class, config('cms.collections.products.model'));
        $this->assertSame('products', config('cms.collections.products.mcp_operations'));
        $this->assertSame('richtext', config('cms.content_schemas.product.description.type'));
        $this->assertSame(['category', 'brand'], config('cms.taxonomy_types'));
        $this->assertArrayHasKey('posts', config('cms.collections'));

        $this->useShop('checkout');
        $this->assertTrue(app(ShopModule::class)->checkout());

        $this->useShop('off');
        $this->assertArrayNotHasKey('products', config('cms.collections'));
        $this->assertSame(['category'], config('cms.taxonomy_types'));
    }

    public function test_admin_panel_renders_with_the_catalog_on_before_the_panel_routes_exist(): void
    {
        $this->useAdminProfile('full');
        $this->useShop('catalog');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_invalid_mode_throws_a_logic_exception(): void
    {
        config(['cms.shop.mode' => 'shop']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invalid cms.shop.mode');
        app(ShopModule::class)->catalog();
    }
}
