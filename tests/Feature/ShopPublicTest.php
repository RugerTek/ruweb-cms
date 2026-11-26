<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Page;
use App\Models\Post;
use App\Models\Product;
use App\Models\Taxonomy;
use App\Models\User;
use App\Services\CustomCode\CustomCodeOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Public shop catalog and sitemap (PLAN.md, stage 1, track 1B). */
class ShopPublicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedLocales();
        $this->useShop('catalog');
    }

    public function test_catalog_lists_only_live_products_of_the_language(): void
    {
        $this->product(['title' => 'Mate de calabaza', 'slug' => 'mate', 'price_amount' => 150000]);
        $this->product(['title' => 'Borrador oculto', 'slug' => 'borrador', 'status' => 'draft', 'published_at' => null]);
        $this->product(['title' => 'Futuro oculto', 'slug' => 'futuro', 'published_at' => now()->addDay()]);
        $this->product(['title' => 'Vencido oculto', 'slug' => 'vencido', 'expires_at' => now()->subMinute()]);
        $this->product(['title' => 'English only', 'slug' => 'english-only', 'locale' => 'en']);

        $this->get('/productos')
            ->assertOk()
            ->assertSee('Mate de calabaza')
            ->assertSee('Gs. 150.000')
            ->assertSee('href="/productos/mate"', false)
            ->assertDontSee('Borrador oculto')
            ->assertDontSee('Futuro oculto')
            ->assertDontSee('Vencido oculto')
            ->assertDontSee('English only');

        $this->get('/productos/mate')->assertOk()->assertSee('Mate de calabaza');
        $this->get('/productos/borrador')->assertNotFound();
        $this->get('/productos/futuro')->assertNotFound();
        $this->get('/productos/vencido')->assertNotFound();
        $this->get('/productos/no-existe')->assertNotFound();
    }

    public function test_catalog_is_served_per_language_with_base_commerce_fields(): void
    {
        $base = $this->product(['title' => 'Termo', 'slug' => 'termo', 'price_amount' => 200000, 'track_stock' => true, 'stock' => 3]);
        $translation = $this->product([
            'locale' => 'en',
            'translation_group_id' => $base->translation_group_id,
            'title' => 'Thermos',
            'slug' => 'thermos',
        ]);
        $this->product(['title' => 'Bombilla', 'slug' => 'bombilla']);

        $this->assertSame('/en/productos/thermos', $translation->url());
        $this->assertSame(route('localized.shop.show', ['locale' => 'en', 'slug' => 'thermos'], false), $translation->url());
        $this->assertSame(route('shop.show', ['slug' => 'termo'], false), $base->url());

        $this->get('/en/productos')
            ->assertOk()
            ->assertSee('Products')
            ->assertSee('Thermos')
            ->assertSee('href="/en/productos/thermos"', false)
            ->assertDontSee('Bombilla')
            ->assertDontSee('>Termo<', false);

        // Prices and stock come from the base-language row; the translation row carries none.
        $this->get('/en/productos/thermos')
            ->assertOk()
            ->assertSee('Thermos')
            ->assertSee('Gs. 200.000') // the currency decides the format, not the language
            ->assertSee('In stock')
            ->assertSee('Back to the catalog')
            ->assertSee('href="'.route('localized.shop.index', ['locale' => 'en']).'"', false)
            ->assertSee('hreflang="es"', false);

        // A language without its own row falls back to the base product; the base prefix is not a locale.
        $this->get('/en/productos/bombilla')->assertRedirect('/productos/bombilla');
        $this->get('/en/productos/no-existe')->assertRedirect('/productos');
        $this->get('/es/productos')->assertRedirect('/productos');
        $this->get('/es/productos/termo')->assertRedirect('/productos/termo');
    }

    public function test_catalog_filters_by_the_brand_taxonomy(): void
    {
        $acme = Taxonomy::create(['type' => 'brand', 'locale' => 'es', 'name' => 'Acme', 'slug' => 'acme']);
        $other = Taxonomy::create(['type' => 'brand', 'locale' => 'es', 'name' => 'Otra Marca', 'slug' => 'otra']);
        $unused = Taxonomy::create(['type' => 'brand', 'locale' => 'es', 'name' => 'Sin Productos', 'slug' => 'sin-productos']);
        $category = Taxonomy::create(['type' => 'category', 'locale' => 'es', 'name' => 'Categoria Blog', 'slug' => 'acme-cat']);

        $this->product(['title' => 'Producto Acme', 'slug' => 'producto-acme'])->taxonomies()->attach($acme);
        $this->product(['title' => 'Producto Otro', 'slug' => 'producto-otro'])->taxonomies()->attach([$other->id, $category->id]);

        $this->get('/productos')
            ->assertOk()
            ->assertSee('Filtrar por marca')
            ->assertSee('Acme')
            ->assertSee('Otra Marca')
            ->assertDontSee('Sin Productos')
            ->assertDontSee('Categoria Blog');

        $this->get('/productos?brand=acme')
            ->assertOk()
            ->assertSee('Producto Acme')
            ->assertDontSee('Producto Otro');

        $this->get('/productos?brand=no-existe')
            ->assertOk()
            ->assertDontSee('Producto Acme')
            ->assertSee('No se encontraron productos');

        // Only the module's registered taxonomy types filter the catalog.
        $this->get('/productos?category=acme-cat')
            ->assertOk()
            ->assertSee('Producto Acme')
            ->assertSee('Producto Otro');

        $this->get('/productos/producto-acme')->assertOk()->assertSee('Acme');
    }

    public function test_products_without_price_render_without_price(): void
    {
        $this->product(['title' => 'Equipo a consulta', 'slug' => 'equipo', 'excerpt' => 'Sin precio publico.']);

        $this->get('/productos')
            ->assertOk()
            ->assertSee('Equipo a consulta')
            ->assertDontSee('Gs.');

        $this->get('/productos/equipo')
            ->assertOk()
            ->assertSee('Equipo a consulta')
            ->assertSee('Sin precio publico.')
            ->assertDontSee('Gs.')
            ->assertDontSee('data-shop-price', false);
    }

    public function test_products_without_image_use_one_column_and_a_listing_placeholder(): void
    {
        $this->product(['title' => 'Equipo sin foto', 'slug' => 'sin-foto', 'price_amount' => 150000]);

        $this->get('/productos')->assertOk()->assertSee('data-shop-image-placeholder', false);
        $this->get('/productos/sin-foto')->assertOk()
            ->assertSee('data-shop-layout="details"', false)
            ->assertDontSee('lg:grid-cols-2', false)
            ->assertDontSee('data-shop-preview-notice', false);
    }

    public function test_variant_query_preselects_a_variant_of_the_product(): void
    {
        $product = $this->product(['title' => 'Remera', 'slug' => 'remera', 'sku' => 'REM', 'has_variants' => true, 'track_stock' => true]);
        $small = $product->variants()->create(['name' => 'Talle S', 'sku' => 'REM-S', 'price_amount' => 90000, 'stock' => 0, 'attributes_json' => ['size' => 'S'], 'order' => 1]);
        $large = $product->variants()->create(['name' => 'Talle L', 'sku' => 'REM-L', 'price_amount' => 95000, 'compare_at_price_amount' => 120000, 'stock' => 4, 'attributes_json' => ['size' => 'L'], 'order' => 2]);
        $foreign = $this->product(['title' => 'Otro', 'slug' => 'otro', 'has_variants' => true])
            ->variants()->create(['name' => 'Ajena', 'sku' => 'OTR-1', 'price_amount' => 1000]);

        $this->get('/productos')->assertOk()->assertSee('Desde Gs. 90.000');

        $this->get('/productos/remera')
            ->assertOk()
            ->assertSee('Desde Gs. 90.000')
            ->assertSee('href="/productos/remera?variante='.$large->id.'"', false)
            ->assertDontSee('aria-current="true"', false)
            ->assertDontSee('En stock');

        $this->get('/productos/remera?variante='.$large->id)
            ->assertOk()
            ->assertSee('SKU: REM-L')
            ->assertSee('Antes Gs. 120.000')
            ->assertSee('En stock')
            ->assertDontSee('Desde Gs.')
            ->assertSee('data-shop-variant="'.$large->id.'"', false)
            ->assertSeeInOrder(['aria-current="true"', 'data-shop-variant="'.$large->id.'"'], false);

        $this->get('/productos/remera?variante='.$small->id)
            ->assertOk()
            ->assertSee('SKU: REM-S')
            ->assertSee('Sin stock');

        // A variant of another product, or garbage, falls back to the unselected view.
        $this->get('/productos/remera?variante='.$foreign->id)->assertOk()->assertSee('Desde Gs. 90.000')->assertSee('SKU: REM');
        $this->get('/productos/remera?variante=abc')->assertOk()->assertSee('Desde Gs. 90.000');
    }

    public function test_sitemap_lists_live_products_only_while_the_module_is_on(): void
    {
        $live = $this->product(['title' => 'Vivo', 'slug' => 'vivo']);
        $this->product(['locale' => 'en', 'translation_group_id' => $live->translation_group_id, 'title' => 'Live', 'slug' => 'live']);
        $this->product(['title' => 'Borrador', 'slug' => 'borrador-sitemap', 'status' => 'draft', 'published_at' => null]);
        $this->product(['title' => 'Vencido', 'slug' => 'vencido-sitemap', 'expires_at' => now()->subMinute()]);
        Post::create(['title' => 'Nota', 'slug' => 'nota', 'status' => 'published', 'published_at' => now()->subHour()]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>'.url('/productos').'</loc>', false)
            ->assertSee('<loc>'.url('/productos/vivo').'</loc>', false)
            ->assertSee('<loc>'.url('/en/productos/live').'</loc>', false)
            ->assertSee('hreflang="en" href="'.url('/en/productos/live').'"', false)
            ->assertSee('<loc>'.url('/blog/nota').'</loc>', false)
            ->assertDontSee('borrador-sitemap')
            ->assertDontSee('vencido-sitemap');

        $this->useShop('off');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>'.url('/blog/nota').'</loc>', false)
            ->assertDontSee('/productos');
    }

    public function test_custom_code_targets_the_catalog_index_per_language(): void
    {
        $this->product(['title' => 'Mate', 'slug' => 'mate']);
        $actor = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => true]);
        $operations = app(CustomCodeOperations::class);
        $record = $operations->save($actor, [
            'name' => 'Catalog index snippet',
            'type' => 'javascript',
            'code' => 'window.shopIndexSnippet = true;',
            'position' => 'body_end',
            'sort_order' => 1,
            'rules' => ['global' => false, 'include' => [['type' => 'indexes', 'id' => 'shop', 'locale' => 'es']], 'exclude' => []],
            'operation_key' => 'shop-index',
        ]);
        $operations->activate($actor, $record['id'], [
            'version_id' => $record['versions'][0]['id'],
            'expected_revision' => $record['revision'],
            'operation_key' => 'shop-index-activate',
        ]);

        $this->get('/productos')->assertOk()->assertSee('window.shopIndexSnippet', false);
        $this->get('/en/productos')->assertOk()->assertDontSee('window.shopIndexSnippet', false);
        $this->get('/productos/mate')->assertOk()->assertDontSee('window.shopIndexSnippet', false);
    }

    public function test_with_the_module_off_the_prefix_is_not_a_catalog(): void
    {
        $this->product(['title' => 'Mate', 'slug' => 'mate']);
        $this->useShop('off');

        // The routes stay registered (route:cache) and hand the request to the page catch-all.
        $this->get('/productos')->assertNotFound();
        $this->get('/en/productos')->assertRedirect('/'); // the localized page catch-all: no page in that language
        $this->get('/productos/mate')->assertNotFound();

        // The prefix is a free page slug again.
        Page::create([
            'title' => 'Nuestros productos',
            'slug' => 'productos',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['heading' => 'Nuestros productos']]],
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get('/productos')->assertOk()->assertSee('Nuestros productos')->assertDontSee('Mate');
    }

    private function product(array $attributes): Product
    {
        return Product::create($attributes + [
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);
    }

    private function seedLocales(): void
    {
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
    }
}
