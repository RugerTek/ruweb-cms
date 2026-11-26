<?php

namespace Tests\Feature;

use App\Connectors\CatalogItem;
use App\Connectors\ConnectorRegistry;
use App\Connectors\Sources\ShopProductSource;
use App\Models\Locale;
use App\Models\Media;
use App\Models\Product;
use App\Models\Taxonomy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Catalog source over the shop products (PLAN.md §4.2, stage 2, track 2A). */
class ShopProductSourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        $this->useShop('catalog');
    }

    public function test_it_is_the_registered_products_source_and_follows_the_catalog_switch(): void
    {
        $source = app(ConnectorRegistry::class)->source('products');

        $this->assertInstanceOf(ShopProductSource::class, $source);
        $this->assertSame('products', $source->key());
        $this->assertTrue($source->available());

        $this->useShop('off');
        $this->assertFalse(app(ShopProductSource::class)->available());

        $this->useShop('catalog', 'off');
        $this->assertFalse(app(ShopProductSource::class)->available());
    }

    public function test_only_live_products_of_the_base_language(): void
    {
        $live = $this->product(['title' => 'Mate', 'slug' => 'mate', 'price_amount' => 150000]);
        $this->product(['title' => 'Borrador', 'slug' => 'borrador', 'status' => 'draft', 'published_at' => null]);
        $this->product(['title' => 'Futuro', 'slug' => 'futuro', 'published_at' => now()->addDay()]);
        $this->product(['title' => 'Vencido', 'slug' => 'vencido', 'expires_at' => now()->subMinute()]);
        $this->product(['title' => 'Gourd', 'slug' => 'gourd', 'locale' => 'en', 'translation_group_id' => $live->translation_group_id]);

        $items = $this->items();

        $this->assertSame(['p'.$live->id], array_keys($items));
        $this->assertSame(1, app(ShopProductSource::class)->count());
        $item = $items['p'.$live->id];
        $this->assertSame('Mate', $item->title);
        $this->assertSame('/productos/mate', $item->url);
        $this->assertNull($item->groupId);
        $this->assertSame(150000, $item->price->amount);
        $this->assertSame('PYG', $item->price->currency);
        $this->assertSame(route('admin.products.edit', $live, false), $item->editUrl);
    }

    public function test_simple_product_uses_its_sku_prices_and_plain_text_description(): void
    {
        $product = $this->product([
            'title' => 'Termo',
            'slug' => 'termo',
            'sku' => 'TER-1',
            'price_amount' => 200000,
            'compare_at_price_amount' => 250000,
            'description' => '<p>Acero <strong>inoxidable</strong> &amp; tapa.</p><p>Ver <a href="https://example.com">más</a></p>',
        ]);
        $plain = $this->product(['title' => 'Bombilla', 'slug' => 'bombilla', 'price_amount' => 30000, 'compare_at_price_amount' => 20000, 'excerpt' => 'Resumen corto']);

        $items = $this->items();

        $termo = $items['TER-1'];
        $this->assertSame(250000, $termo->price->amount);
        $this->assertSame(200000, $termo->salePrice->amount);
        $this->assertSame("Acero inoxidable & tapa.\nVer más", $termo->description);
        $this->assertStringNotContainsString('<', $termo->description);
        $this->assertStringNotContainsString('example.com', $termo->description);

        // A compare-at price lower than the price is not a sale; the excerpt wins over the description.
        $bombilla = $items['p'.$plain->id];
        $this->assertSame(30000, $bombilla->price->amount);
        $this->assertNull($bombilla->salePrice);
        $this->assertSame('Resumen corto', $bombilla->description);
        $this->assertSame('TER-1', $product->sku);
    }

    public function test_one_item_per_variant_with_its_group_price_stock_and_attributes(): void
    {
        $product = $this->product(['title' => 'Remera', 'slug' => 'remera', 'sku' => 'REM', 'has_variants' => true, 'price_amount' => 99000, 'track_stock' => true]);
        $red = $product->variants()->create(['sku' => 'REM-R', 'name' => 'Roja', 'attributes_json' => ['color' => 'Rojo', 'size' => 'M', 'Tela' => 'Algodón'], 'price_amount' => 80000, 'compare_at_price_amount' => 90000, 'stock' => 4, 'order' => 0]);
        $blue = $product->variants()->create(['name' => 'Azul', 'attributes_json' => ['color' => 'Azul'], 'price_amount' => 85000, 'stock' => 0, 'order' => 1]);

        $items = $this->items();

        $this->assertSame(['REM-R', 'v'.$blue->id], array_keys($items));
        $this->assertSame(2, app(ShopProductSource::class)->count());

        $first = $items['REM-R'];
        $this->assertSame('REM', $first->groupId);
        $this->assertSame('Remera - Roja', $first->title);
        $this->assertSame('/productos/remera?variante='.$red->id, $first->url);
        $this->assertSame(90000, $first->price->amount);
        $this->assertSame(80000, $first->salePrice->amount);
        $this->assertSame(CatalogItem::IN_STOCK, $first->availability);
        $this->assertSame(['color' => 'Rojo', 'size' => 'M', 'Tela' => 'Algodón'], $first->variantAttributes);
        $this->assertFalse(ShopProductSource::lacksVariants($first));

        $second = $items['v'.$blue->id];
        $this->assertSame('REM', $second->groupId);
        $this->assertSame(85000, $second->price->amount);
        $this->assertNull($second->salePrice);
        $this->assertSame(CatalogItem::OUT_OF_STOCK, $second->availability); // sold out is still listed
    }

    public function test_product_with_variants_enabled_but_none_is_flagged(): void
    {
        $product = $this->product(['title' => 'Sin variantes', 'slug' => 'sin-variantes', 'has_variants' => true]);
        $product->load('variants');

        $items = $this->items();

        $item = $items['p'.$product->id];
        $this->assertSame('p'.$product->id, $item->groupId);
        $this->assertTrue($item->lacksVariants());
        $this->assertTrue(ShopProductSource::lacksVariants($item));
        $this->assertSame(1, app(ShopProductSource::class)->count());
    }

    public function test_availability_follows_stock_tracking_and_backorders(): void
    {
        $this->product(['slug' => 'con-stock', 'sku' => 'A', 'track_stock' => true, 'stock' => 2]);
        $this->product(['slug' => 'agotado', 'sku' => 'B', 'track_stock' => true, 'stock' => 0]);
        $this->product(['slug' => 'encargo', 'sku' => 'C', 'track_stock' => true, 'allow_backorder' => true, 'stock' => 0]);
        $this->product(['slug' => 'sin-control', 'sku' => 'D', 'track_stock' => false, 'stock' => 0]);

        $items = $this->items();

        $this->assertSame(CatalogItem::IN_STOCK, $items['A']->availability);
        $this->assertSame(CatalogItem::OUT_OF_STOCK, $items['B']->availability);
        $this->assertSame(CatalogItem::IN_STOCK, $items['C']->availability);
        $this->assertNull($items['D']->availability);
    }

    public function test_missing_price_and_image_reach_the_connector_as_missing_price_and_missing_image(): void
    {
        $fake = $this->useFakeConnectors();
        $source = app(ShopProductSource::class);
        $this->product(['slug' => 'sin-nada', 'sku' => 'NADA']);

        $item = $source->items()->first();
        $codes = array_map(fn ($warning) => $warning->code, $fake->check($item, []));

        $this->assertNull($item->price);
        $this->assertNull($item->imageMediaId);
        $this->assertContains('missing_price', $codes);
        $this->assertContains('missing_image', $codes);
    }

    public function test_images_come_from_the_featured_image_and_the_gallery(): void
    {
        $product = $this->product(['slug' => 'con-fotos', 'sku' => 'FOTO', 'price_amount' => 1000]);
        $featured = $this->media('portada.webp', 'image/webp');
        $second = $this->media('detalle.webp', 'image/webp');
        $third = $this->media('lado.jpg', 'image/jpeg');
        $pdf = $this->media('manual.pdf', 'application/pdf');
        $product->attachMedia($featured->id, 'featured_image');
        $product->attachMedia($third->id, 'gallery', 1);
        $product->attachMedia($second->id, 'gallery', 0);
        $product->attachMedia($pdf->id, 'gallery', 2);

        $item = $this->items()['FOTO'];

        $this->assertSame($featured->id, $item->imageMediaId);
        $this->assertSame([$second->id, $third->id], $item->additionalImageMediaIds);
    }

    public function test_brand_comes_from_the_brand_taxonomy_or_is_null(): void
    {
        $acme = Taxonomy::create(['type' => 'brand', 'locale' => 'es', 'name' => 'Acme', 'slug' => 'acme']);
        $category = Taxonomy::create(['type' => 'category', 'locale' => 'es', 'name' => 'Categoria', 'slug' => 'categoria']);
        $this->product(['slug' => 'con-marca', 'sku' => 'M1'])->taxonomies()->attach([$category->id, $acme->id]);
        $this->product(['slug' => 'sin-marca', 'sku' => 'M2'])->taxonomies()->attach($category->id);

        $items = $this->items();

        $this->assertSame('Acme', $items['M1']->brand);
        $this->assertNull($items['M2']->brand);
    }

    public function test_items_stream_in_chunks_across_many_products(): void
    {
        for ($i = 1; $i <= 205; $i++) {
            $this->product(['slug' => 'producto-'.$i, 'sku' => 'S'.$i]);
        }

        $items = app(ShopProductSource::class)->items();

        $this->assertSame(205, $items->count());
        $this->assertSame(205, app(ShopProductSource::class)->count());
        $this->assertSame('S1', $items->first()->id);
        $this->assertSame('S205', $items->last()->id);
    }

    /** @return array<string, CatalogItem> */
    private function items(): array
    {
        return app(ShopProductSource::class)->items()->keyBy(fn (CatalogItem $item) => $item->id)->all();
    }

    private function product(array $attributes): Product
    {
        return Product::create($attributes + [
            'title' => 'Producto '.($attributes['slug'] ?? ''),
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);
    }

    private function media(string $filename, string $mime): Media
    {
        return Media::create([
            'filename' => $filename,
            'original_filename' => $filename,
            'path' => 'media/2026/11/'.$filename,
            'mime_type' => $mime,
            'extension' => pathinfo($filename, PATHINFO_EXTENSION),
            'size' => 1000,
            'disk' => 'public',
        ]);
    }
}
