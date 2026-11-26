<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Media;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\Taxonomy;
use App\Models\User;
use App\Services\Editorial\EditorialHistory;
use App\Services\Products\ProductOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/** Shop product panel (Full CRUD, Essential operational list) and product links in panel menus (PLAN.md, track 1C). */
class AdminProductsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        $this->useShop('catalog');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
    }

    public function test_full_admin_creates_a_simple_product_with_typed_prices(): void
    {
        $this->useAdminProfile('full');
        $brand = Taxonomy::create(['name' => 'Acme', 'slug' => 'acme', 'type' => 'brand', 'locale' => 'es']);
        [$cover, $side, $back] = array_map(fn (string $name) => Media::create([
            'filename' => $name, 'original_filename' => $name, 'path' => 'media/tests/'.$name, 'mime_type' => 'image/webp',
            'extension' => 'webp', 'size' => 1024, 'disk' => 'public', 'uploaded_by' => $this->admin->id,
        ]), ['cover.webp', 'side.webp', 'back.webp']);
        $this->actingAs($this->admin);

        $this->get('/admin/products')->assertOk()->assertSee(__('shop.no_products_yet'))->assertSee(route('admin.products.create'));
        $this->get('/admin/products/create')->assertOk()->assertSee('name="price_amount"', false)->assertSee('data-repeater-key="variants"', false)->assertSee('Acme');

        $response = $this->post('/admin/products', $this->form([
            'title' => 'Mate de calabaza', 'slug' => 'mate', 'status' => 'published', 'sku' => 'MATE-1',
            'price_amount' => '150.000', 'compare_at_price_amount' => '180.000', 'track_stock' => '1', 'stock' => '7',
            'brands' => [$brand->id], 'meta_json' => ['title' => 'Mate SEO', 'description' => ''], 'featured_image' => (string) $cover->id,
            'items' => ['gallery' => [['media_id' => (string) $back->id], ['media_id' => ''], ['media_id' => (string) $side->id]]],
        ]));

        $product = Product::where('slug', 'mate')->firstOrFail();
        $response->assertRedirect(route('admin.products.edit', $product))->assertSessionHas('success', __('shop.product_created'));
        $this->assertSame(150000, $product->price_amount->amount);
        $this->assertSame(180000, $product->compare_at_price_amount->amount);
        $this->assertSame('published', $product->status);
        $this->assertSame(7, $product->stock);
        $this->assertSame(['title' => 'Mate SEO'], $product->meta_json);
        $this->assertSame([$brand->id], $product->brands()->pluck('taxonomies.id')->all());
        $this->assertSame($cover->id, $product->featuredImage()?->id);
        $this->assertSame([$back->id, $side->id], $product->mediaByCollection('gallery')->orderBy('mediables.order')->pluck('media.id')->all());

        $this->get('/admin/products/'.$product->id.'/edit')->assertOk()->assertSee('value="150.000"', false)->assertSee('value="180.000"', false);
        $this->get('/admin/products')->assertOk()->assertSee('Mate de calabaza')->assertSee('Gs. 150.000')->assertSee(route('admin.products.destroy', $product));
        $this->get('/admin/products?search=MATE-1')->assertOk()->assertSee('Mate de calabaza');
        $this->get('/productos/mate')->assertOk()->assertSee('Mate de calabaza');

        // Empty price = catalog item without public price; a non-numeric one is rejected, not turned into 0.
        $this->put('/admin/products/'.$product->id, $this->form(['title' => 'Mate de calabaza', 'slug' => 'mate', 'status' => 'published', 'price_amount' => '']))
            ->assertRedirect();
        $this->assertNull($product->fresh()->price_amount);
        $this->from('/admin/products/'.$product->id.'/edit')
            ->put('/admin/products/'.$product->id, $this->form(['title' => 'Mate de calabaza', 'slug' => 'mate', 'status' => 'published', 'price_amount' => 'mucho']))
            ->assertRedirect('/admin/products/'.$product->id.'/edit')->assertSessionHasErrors('price_amount');
        $this->assertNull($product->fresh()->price_amount);
    }

    public function test_full_admin_edits_variants_reorders_previews_and_deletes(): void
    {
        $this->useAdminProfile('full');
        $this->actingAs($this->admin);

        $this->post('/admin/products', $this->form([
            'title' => 'Remera', 'slug' => 'remera', 'status' => 'draft', 'sku' => 'REM', 'has_variants' => '1',
            'price_amount' => '1.000', // ignored: with variants the price lives on each variant
            'items' => ['variants' => [
                ['id' => '', 'name' => 'Rojo S', 'sku' => 'REM-RS', 'price_amount' => '99.000', 'compare_at_price_amount' => '120.000', 'stock' => '', 'weight_grams' => '200', 'attributes' => "Color: Rojo\nTalle: S"],
                ['id' => '', 'name' => 'Azul M', 'sku' => 'REM-AM', 'price_amount' => '109.000', 'compare_at_price_amount' => '', 'stock' => '', 'weight_grams' => '', 'attributes' => ''],
            ]],
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $product = Product::where('slug', 'remera')->firstOrFail();
        [$red, $blue] = $product->variants->all();
        $this->assertTrue($product->has_variants);
        $this->assertNull($product->price_amount);
        $this->assertSame(['Rojo S', 'Azul M'], $product->variants->pluck('name')->all());
        $this->assertSame(99000, $red->price_amount->amount);
        $this->assertSame(120000, $red->compare_at_price_amount->amount);
        $this->assertSame(['Color' => 'Rojo', 'Talle' => 'S'], $red->attributes_json);
        $this->assertNull($blue->attributes_json);
        $this->assertSame(99000, $product->displayPrice()->amount);

        $this->get('/admin/products/'.$product->id.'/edit')->assertOk()
            ->assertSee('value="99.000"', false)->assertSee('Rojo S')->assertSee("Color: Rojo\nTalle: S")
            ->assertSee('name="items[variants][1][id]" value="'.$blue->id.'"', false);
        $this->get('/admin/products?search=REM-AM')->assertOk()->assertSee('Remera');

        // Unsaved form rendered with the public view: nothing is written.
        $this->post('/admin/products/'.$product->id.'/preview', $this->form([
            'title' => 'Remera nueva', 'slug' => 'remera', 'status' => 'draft', 'has_variants' => '1',
            'items' => ['variants' => [['id' => $blue->id, 'name' => 'Azul M', 'sku' => 'REM-AM', 'price_amount' => '109.000']]],
        ]))->assertOk()->assertSee('Remera nueva')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->assertSame('Remera', $product->fresh()->title);

        // Reorder (blue first), drop red, add green; a stale revision is a conflict, not a silent overwrite.
        $revision = app(ProductOperations::class)->revision($product);
        $update = $this->form([
            'title' => 'Remera', 'slug' => 'remera', 'status' => 'published', 'sku' => 'REM', 'has_variants' => '1',
            'expected_revision' => $revision,
            'items' => ['variants' => [
                ['id' => (string) $blue->id, 'name' => 'Azul M', 'sku' => 'REM-AM', 'price_amount' => '119.000'],
                ['id' => '', 'name' => 'Verde L', 'sku' => 'REM-VL', 'price_amount' => ''],
            ]],
        ]);
        $this->put('/admin/products/'.$product->id, $update)->assertSessionHasNoErrors()->assertSessionHas('success', __('shop.product_updated'));
        $product->refresh();
        $this->assertSame('published', $product->status);
        $this->assertSame(['Azul M', 'Verde L'], $product->variants->pluck('name')->all());
        $this->assertSame($blue->id, $product->variants[0]->id);
        $this->assertSame(119000, $product->variants[0]->price_amount->amount);
        $this->assertNull($product->variants[1]->price_amount);
        $this->assertDatabaseMissing('product_variants', ['id' => $red->id]);

        $this->from('/admin/products/'.$product->id.'/edit')->put('/admin/products/'.$product->id, $update)
            ->assertRedirect('/admin/products/'.$product->id.'/edit')->assertSessionHasErrors('expected_revision');

        // A product with variants needs at least one; turning variants off makes it simple again.
        $this->from('/admin/products/'.$product->id.'/edit')
            ->put('/admin/products/'.$product->id, $this->form(['title' => 'Remera', 'slug' => 'remera', 'status' => 'published', 'has_variants' => '1']))
            ->assertSessionHasErrors('variants');
        $this->put('/admin/products/'.$product->id, $this->form(['title' => 'Remera', 'slug' => 'remera', 'status' => 'published', 'has_variants' => '0', 'price_amount' => '50.000',
            'items' => ['variants' => [['id' => (string) $blue->id, 'name' => 'Azul M']]]]))->assertSessionHasNoErrors();
        $this->assertSame(0, $product->variants()->count());
        $this->assertSame(50000, $product->fresh()->price_amount->amount);

        $this->delete('/admin/products/'.$product->id)->assertRedirect(route('admin.products.index'))->assertSessionHas('success', __('shop.product_deleted'));
        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertSame(0, DB::table('product_variants')->where('product_id', $product->id)->count());
    }

    public function test_edit_links_the_shared_history_creates_translations_and_restores_versions(): void
    {
        $this->useAdminProfile('full');
        $this->actingAs($this->admin);
        $history = app(EditorialHistory::class);
        $operations = app(ProductOperations::class);
        $product = $operations->save($this->admin, ['title' => 'Mate', 'slug' => 'mate', 'status' => 'draft', 'sku' => 'MATE', 'price_amount' => 150000]);
        $original = (int) $history->query($product)->where('is_proposal', false)->latest('id')->value('id');
        $product = $operations->save($this->admin, ['title' => 'Mate cambiado', 'price_amount' => 99000], $product, patch: true);

        $this->get('/admin/products/'.$product->id.'/edit')->assertOk()
            ->assertSee(route('admin.editorial.index', ['products', $product->id]), false)
            ->assertSee('id="editor-language-switcher"', false)
            ->assertSee('data-create-url="'.route('admin.products.translate', [$product, 'en']).'"', false);

        // The shared history screen: versions, comparison, review and shareable preview links work for products.
        $this->get('/admin/editorial/products/'.$product->id.'?version='.$original)->assertOk()
            ->assertSee('#'.$original)->assertSee('Mate cambiado')
            ->assertSee(route('admin.editorial.share', ['products', $product->id]), false);
        $this->from('/admin/editorial/products/'.$product->id)->post('/admin/editorial/products/'.$product->id.'/share', ['hours' => 2])
            ->assertRedirect('/admin/editorial/products/'.$product->id)->assertSessionHas('previewUrl');
        $this->get(parse_url(session('previewUrl'), PHP_URL_PATH))->assertOk()->assertSee('Mate cambiado');

        // Restoring from the history goes back to the product form with the old content and price.
        $this->post('/admin/editorial/products/'.$product->id.'/restore/'.$original, ['_editor_base' => $history->fingerprint($product->fresh())])
            ->assertRedirect(route('admin.products.edit', $product))->assertSessionHas('success', __('editorial.restored'));
        $this->assertSame('Mate', $product->fresh()->title);
        $this->assertSame(150000, $product->fresh()->price_amount->amount);

        // Translation from the panel: a draft row of the group without commerce fields; asking again opens it.
        $this->post(route('admin.products.translate', [$product, 'en']))->assertSessionHas('success', __('shop.product_created'));
        $translation = Product::where('locale', 'en')->where('translation_group_id', $product->translation_group_id)->firstOrFail();
        $this->assertSame(['Mate', 'draft'], [$translation->title, $translation->status]);
        $this->assertNull($translation->sku);
        $this->assertNull($translation->price_amount);
        $this->post(route('admin.products.translate', [$product, 'en']))->assertRedirect(route('admin.products.edit', $translation));
        $this->assertSame(1, Product::where('locale', 'en')->count());
        $this->get('/admin/products/'.$translation->id.'/edit')->assertOk()
            ->assertSee('data-url="'.route('admin.products.edit', $product), false)
            ->assertDontSee('name="price_amount"', false);
        $this->post(route('admin.products.translate', [$product, 'xx']))->assertSessionHasErrors('locale');

        $this->useAdminProfile('essential');
        $this->post(route('admin.products.translate', [$product, 'en']))->assertNotFound();
    }

    public function test_editor_saves_drafts_but_never_publishes_or_deletes(): void
    {
        $this->useAdminProfile('full');
        $this->actingAs($this->editor);

        $this->get('/admin/products/create')->assertOk()->assertDontSee('<option value="published"', false)->assertDontSee('name="published_at"', false);
        $this->post('/admin/products', $this->form(['title' => 'Publicado', 'slug' => 'publicado', 'status' => 'published', 'price_amount' => '10.000']))->assertForbidden();
        $this->assertDatabaseMissing('products', ['slug' => 'publicado']);

        $this->post('/admin/products', $this->form(['title' => 'Borrador', 'slug' => 'borrador', 'status' => 'draft', 'price_amount' => '10.000']))->assertSessionHasNoErrors()->assertRedirect();
        $draft = Product::where('slug', 'borrador')->firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->put('/admin/products/'.$draft->id, $this->form(['title' => 'Borrador', 'slug' => 'borrador', 'status' => 'published']))->assertForbidden();
        $this->assertSame('draft', $draft->fresh()->status);

        // A change to a live product becomes a proposal; the public product stays as it was.
        $live = app(ProductOperations::class)->save($this->admin, ['title' => 'En vivo', 'slug' => 'en-vivo', 'status' => 'published', 'price_amount' => 20000]);
        $this->get('/admin/products/'.$live->id.'/edit')->assertOk()->assertDontSee(__('shop.delete_product'));
        $this->put('/admin/products/'.$live->id, $this->form(['title' => 'Cambio del editor', 'slug' => 'en-vivo', 'status' => 'published', 'price_amount' => '25.000']))
            ->assertRedirect()->assertSessionHas('success', __('editorial.staged'));
        $this->assertSame('En vivo', $live->fresh()->title);
        $this->assertSame(20000, $live->fresh()->price_amount->amount);
        $this->assertSame(1, DB::table('editorial_versions')->where('content_type', 'products')->where('content_id', $live->id)->where('is_proposal', true)->count());

        $this->delete('/admin/products/'.$live->id)->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $live->id]);
    }

    public function test_essential_lists_and_previews_but_has_no_product_crud(): void
    {
        $this->useAdminProfile('essential');
        $this->actingAs($this->admin);
        $product = app(ProductOperations::class)->save($this->admin, ['title' => 'Yerba operativa', 'slug' => 'yerba', 'status' => 'draft', 'price_amount' => 30000]);
        $before = $product->fresh()->getRawOriginal();

        $this->get('/admin/content/products')->assertOk()->assertSee('data-content-list', false)->assertSee('Yerba operativa')
            ->assertSee(route('admin.content.preview', ['products', $product->id]))->assertDontSee('/admin/products/'.$product->id.'/edit');
        $this->get('/admin/products')->assertOk()->assertSee('data-content-list', false)->assertSee('Yerba operativa');
        $this->get('/admin/content/products/'.$product->id.'/preview')->assertOk()->assertSee('Yerba operativa')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        $this->get('/admin/products/create')->assertNotFound();
        $this->get('/admin/products/'.$product->id.'/edit')->assertNotFound();
        $this->post('/admin/products', $this->form(['title' => 'Nuevo', 'slug' => 'nuevo', 'status' => 'draft']))->assertNotFound();
        $this->put('/admin/products/'.$product->id, $this->form(['title' => 'Cambio', 'slug' => 'yerba', 'status' => 'draft']))->assertNotFound();
        $this->post('/admin/products/'.$product->id.'/preview', $this->form(['title' => 'Cambio', 'slug' => 'yerba']))->assertNotFound();
        $this->delete('/admin/products/'.$product->id)->assertNotFound();
        $this->assertSame($before, $product->fresh()->getRawOriginal());
        $this->assertDatabaseMissing('products', ['slug' => 'nuevo']);
    }

    public function test_panel_routes_do_not_exist_with_the_shop_off(): void
    {
        $this->useShop('off');
        $this->useAdminProfile('full');
        $this->actingAs($this->admin);
        $brand = Taxonomy::create(['type' => 'brand', 'locale' => 'es', 'name' => 'Acme', 'slug' => 'acme']);

        $this->get('/admin/products')->assertNotFound();
        $this->get('/admin/products/create')->assertNotFound();

        // Panel taxonomy screens follow cms.taxonomy_types: brands are not editable with the shop off, nor unknown types.
        $this->get('/admin/taxonomies/category')->assertOk();
        $this->get('/admin/taxonomies/brand')->assertNotFound();
        $this->get('/admin/taxonomies/brand/create')->assertNotFound();
        $this->get('/admin/taxonomies/brand/'.$brand->id.'/edit')->assertNotFound();
        $this->post('/admin/taxonomies/brand', ['name' => 'Otra', 'slug' => 'otra'])->assertNotFound();
        $this->get('/admin/taxonomies/foo')->assertNotFound();
        $this->assertSame(1, Taxonomy::where('type', 'brand')->count());
    }

    public function test_brand_screens_are_translated_and_highlight_only_the_shop_group(): void
    {
        $this->useAdminProfile('full');
        $this->actingAs($this->admin);
        $brand = Taxonomy::create(['type' => 'brand', 'locale' => 'es', 'name' => 'Acme', 'slug' => 'acme']);

        foreach (['/admin/taxonomies/brand', '/admin/taxonomies/brand/create', '/admin/taxonomies/brand/'.$brand->id.'/edit'] as $url) {
            $html = $this->get($url)->assertOk()->assertSee('Marcas')->assertDontSee('admin.taxonomy_')->assertDontSee('Nueva Marcas')->getContent();
            $this->assertSidebarGroups($html, posts: false, shop: true);
        }
        $this->get('/admin/taxonomies/brand')->assertSee('Nueva marca');
        $this->assertSidebarGroups($this->get('/admin/taxonomies/category')->assertOk()->assertSee('Nueva categoría')->getContent(), posts: true, shop: false);

        // Every registered type has its panel label in both languages (the menu editor uses it too).
        foreach (config('cms.taxonomy_types') as $type) {
            foreach (['es', 'en'] as $locale) {
                foreach (['admin.taxonomy_type_', 'admin.taxonomy_singular_'] as $prefix) {
                    $this->assertTrue(Lang::has($prefix.$type, $locale, false), "{$prefix}{$type} ({$locale})");
                }
            }
        }
        $this->assertSame('Brands', __('admin.taxonomy_type_brand', [], 'en'));
    }

    public function test_preview_is_flagged_and_the_language_switcher_carries_the_product_return(): void
    {
        $this->useAdminProfile('full');
        $this->actingAs($this->admin);
        $product = app(ProductOperations::class)->save($this->admin, ['title' => 'Mate', 'slug' => 'mate', 'status' => 'published', 'price_amount' => 150000]);

        $this->post('/admin/products/'.$product->id.'/preview', $this->form(['title' => 'Mate nuevo', 'slug' => 'mate', 'status' => 'published', 'price_amount' => '175.000']))
            ->assertOk()->assertSee('Mate nuevo')->assertSee('data-shop-preview-notice', false)->assertSee(__('shop.preview_notice'));
        $this->get('/productos/mate')->assertOk()->assertDontSee('data-shop-preview-notice', false);

        $this->get('/admin/products/'.$product->id.'/edit')->assertOk()
            ->assertSee('data-return-form="#product-form"', false)
            ->assertSee('placeholder="'.__('shop.field_slug_placeholder').'"', false);
    }

    /** Whether the Posts and Tienda sidebar groups render open and their entries highlighted. */
    private function assertSidebarGroups(string $html, bool $posts, bool $shop): void
    {
        foreach (['posts-group' => $posts, 'shop-group' => $shop] as $group => $active) {
            $this->assertSame(1, preg_match('/<ul id="'.$group.'"\s+class="([^"]*)"/', $html, $match), $group);
            $this->assertSame(! $active, str_contains($match[1], 'hidden'), $group.' open state');
        }
        foreach (['category' => $posts, 'brand' => $shop] as $type => $active) {
            $this->assertSame(1, preg_match('#<a href="'.preg_quote(route('admin.taxonomies.index', $type), '#').'"\s+class="([^"]*)"#', $html, $match), $type);
            $this->assertSame($active, str_contains($match[1], 'text-sky-400'), $type.' link highlight');
        }
    }

    public function test_menus_link_products_while_the_catalog_is_on_and_hide_them_when_it_is_off(): void
    {
        $this->useAdminProfile('full');
        $this->actingAs($this->admin);
        $product = app(ProductOperations::class)->save($this->admin, ['title' => 'Mate imperial', 'slug' => 'mate-imperial', 'status' => 'published', 'price_amount' => 90000]);
        app(ProductOperations::class)->save($this->admin, ['title' => 'Mate borrador', 'slug' => 'mate-borrador', 'status' => 'draft']);
        $menu = Menu::create(['name' => 'Header', 'slug' => 'header', 'locale' => 'es', 'location' => 'header']);

        $this->get('/admin/menus/'.$menu->id.'/edit')->assertOk()->assertSee('panel-products')->assertSee('Mate imperial')->assertDontSee('Mate borrador');

        $this->put('/admin/menus/'.$menu->id.'/items', ['items' => [
            ['id' => 1, 'parent_id' => null, 'label' => 'Inicio', 'type' => 'custom_link', 'linkable_id' => null, 'url' => '/', 'target' => '_self', 'order' => 0],
            ['id' => 2, 'parent_id' => null, 'label' => 'Mate', 'type' => 'product', 'linkable_id' => $product->id, 'url' => null, 'target' => '_self', 'order' => 1],
        ]])->assertSessionHasNoErrors()->assertRedirect(route('admin.menus.edit', $menu));

        $item = MenuItem::where('menu_id', $menu->id)->where('type', 'product')->firstOrFail();
        $this->assertSame(Product::class, $item->linkable_type);
        $this->assertSame('/productos/mate-imperial', $item->resolveUrl());
        $this->assertSame(['Inicio', 'Mate'], $menu->tree(public: true)->pluck('label')->all());
        $this->assertStringContainsString('href="/productos/mate-imperial"', Blade::render('<x-menu-component slug="header" />'));

        // Catalog off: the stored item stays, public menus drop it and the panel no longer offers products.
        $this->useShop('off');
        $this->assertSame('#', $item->fresh()->resolveUrl());
        $this->assertSame(['Inicio'], $menu->fresh()->tree(public: true)->pluck('label')->all());
        $this->assertSame(['Inicio', 'Mate'], $menu->fresh()->tree()->pluck('label')->all());
        $this->assertStringNotContainsString('mate-imperial', Blade::render('<x-menu-component slug="header" />'));
        $this->get('/admin/menus/'.$menu->id.'/edit')->assertOk()->assertDontSee('panel-products')
            ->assertSee('"type":"product","linkable_id":'.$product->id, false);

        // Saving the menu keeps the existing product item as it is (no catalog check, no data loss)…
        $this->from('/admin/menus/'.$menu->id.'/edit')->put('/admin/menus/'.$menu->id.'/items', ['items' => [
            ['id' => 10, 'parent_id' => null, 'label' => 'Inicio nuevo', 'type' => 'custom_link', 'linkable_id' => null, 'url' => '/', 'target' => '_self', 'order' => 0],
            ['id' => 11, 'parent_id' => null, 'label' => 'Mate', 'type' => 'product', 'linkable_id' => $product->id, 'url' => null, 'target' => '_self', 'order' => 1],
        ]])->assertSessionHasNoErrors()->assertRedirect(route('admin.menus.edit', $menu));
        $kept = MenuItem::where('menu_id', $menu->id)->where('type', 'product')->sole();
        $this->assertSame([Product::class, $product->id], [$kept->linkable_type, $kept->linkable_id]);
        $this->assertSame(['Inicio nuevo'], $menu->fresh()->tree(public: true)->pluck('label')->all());

        // …but a new product item (another product, or the same one twice) is refused and nothing is written.
        $other = Product::where('slug', 'mate-borrador')->firstOrFail();
        foreach ([$other->id, $product->id] as $target) {
            $this->from('/admin/menus/'.$menu->id.'/edit')->put('/admin/menus/'.$menu->id.'/items', ['items' => [
                ['id' => 11, 'parent_id' => null, 'label' => 'Mate', 'type' => 'product', 'linkable_id' => $product->id, 'url' => null, 'target' => '_self', 'order' => 0],
                ['id' => 12, 'parent_id' => null, 'label' => 'Otro', 'type' => 'product', 'linkable_id' => $target, 'url' => null, 'target' => '_self', 'order' => 1],
            ]])->assertSessionHasErrors('items.1.type');
        }
        $this->assertSame(['Inicio nuevo', 'Mate'], MenuItem::where('menu_id', $menu->id)->orderBy('order')->pluck('label')->all());
    }

    /** Full-form defaults of the panel (hidden checkbox values included). */
    private function form(array $fields): array
    {
        return $fields + ['has_variants' => '0', 'track_stock' => '0', 'allow_backorder' => '0', 'locale' => 'es'];
    }
}
