<?php

namespace Tests\Feature;

use App\Connectors\Meta\MetaFeedWriter;
use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Media;
use App\Models\Product;
use App\Models\Taxonomy;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Editorial\EditorialHistory;
use App\Services\Editorial\EditorialOperations;
use App\Services\Products\ProductOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Product categories from the panel and MCP, with history, and their use as product_type / custom_label_0 in the Meta feed. */
class ShopProductCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Taxonomy $clothes;

    private Taxonomy $shirts;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        $this->useShop('catalog');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->clothes = Taxonomy::create(['locale' => 'es', 'name' => 'Ropa', 'slug' => 'ropa', 'type' => 'category']);
        $this->shirts = Taxonomy::create(['locale' => 'es', 'name' => 'Remeras', 'slug' => 'remeras', 'type' => 'category', 'parent_id' => $this->clothes->id]);
    }

    public function test_panel_form_lists_categories_with_their_path_and_saves_and_clears_them_with_history(): void
    {
        $this->useAdminProfile('full');
        $this->actingAs($this->admin);

        $this->get('/admin/products/create')->assertOk()
            ->assertSee('name="categories[]"', false)
            ->assertSee('Ropa &gt; Remeras', false)
            ->assertSee(__('shop.categories_help'));

        $this->post('/admin/products', $this->form(['title' => 'Remera lisa', 'slug' => 'remera-lisa', 'status' => 'draft', 'categories' => [(string) $this->shirts->id]]))
            ->assertSessionHasNoErrors();
        $product = Product::where('slug', 'remera-lisa')->firstOrFail();
        $this->assertSame([$this->shirts->id], $product->categories()->pluck('taxonomies.id')->all());
        $this->get('/admin/products/'.$product->id.'/edit')->assertOk()
            ->assertSee('value="'.$this->shirts->id.'" checked', false);

        $history = app(EditorialHistory::class);
        $versions = $history->query($product)->where('is_proposal', false)->count();
        $revision = app(ProductOperations::class)->revision($product);
        $this->put('/admin/products/'.$product->id, $this->form(['title' => 'Remera lisa', 'slug' => 'remera-lisa', 'status' => 'draft', 'expected_revision' => $revision]))
            ->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame([], $product->categories()->pluck('taxonomies.id')->all());
        $this->assertNotSame($revision, app(ProductOperations::class)->revision($product));
        $this->assertGreaterThan($versions, $history->query($product)->where('is_proposal', false)->count());
        $latest = json_decode($history->query($product)->where('is_proposal', false)->latest('id')->value('payload'), true);
        $this->assertNotContains($this->shirts->id, $latest['taxonomies']);
        $this->assertSame($this->admin->id, (int) $history->query($product)->where('is_proposal', false)->latest('id')->value('user_id'));
    }

    public function test_panel_rejects_brands_and_other_language_categories_and_keeps_brands_apart(): void
    {
        $this->useAdminProfile('full');
        $this->actingAs($this->admin);
        $brand = Taxonomy::create(['locale' => 'es', 'name' => 'Acme', 'slug' => 'acme', 'type' => Product::BRAND_TAXONOMY]);
        $english = Taxonomy::create(['locale' => 'en', 'name' => 'Clothing', 'slug' => 'clothing', 'type' => 'category']);

        foreach ([$brand->id, $english->id, 999999] as $wrong) {
            $this->post('/admin/products', $this->form(['title' => 'Mate', 'slug' => 'mate', 'status' => 'draft', 'categories' => [(string) $wrong]]))
                ->assertSessionHasErrors(['categories.0' => __('shop.category_invalid')]);
        }
        $this->assertSame(0, Product::count());

        $this->post('/admin/products', $this->form(['title' => 'Mate', 'slug' => 'mate', 'status' => 'draft', 'brands' => [(string) $brand->id], 'categories' => [(string) $this->clothes->id]]))
            ->assertSessionHasNoErrors();
        $product = Product::where('slug', 'mate')->firstOrFail();
        $this->assertSame([$brand->id], $product->brands()->pluck('taxonomies.id')->all());
        $this->assertSame([$this->clothes->id], $product->categories()->pluck('taxonomies.id')->all());
    }

    public function test_an_editor_change_to_a_live_product_stages_its_categories_and_restoring_brings_them_back(): void
    {
        $operations = app(ProductOperations::class);
        $history = app(EditorialHistory::class);
        $product = $operations->save($this->admin, ['title' => 'Remera', 'slug' => 'remera', 'status' => 'published', 'categories' => [$this->shirts->id]]);
        $withShirts = (int) $history->query($product)->where('is_proposal', false)->latest('id')->value('id');

        $editor = User::factory()->create(['role' => 'editor']);
        $operations->save($editor, ['title' => 'Remera', 'slug' => 'remera', 'status' => 'published', 'categories' => [$this->clothes->id]], $product);
        $this->assertSame([$this->shirts->id], $product->categories()->pluck('taxonomies.id')->all());
        $proposal = json_decode($history->query($product)->where('is_proposal', true)->latest('id')->value('payload'), true);
        $this->assertContains($this->clothes->id, $proposal['taxonomies']);
        $this->assertNotContains($this->shirts->id, $proposal['taxonomies']);

        $product = $operations->save($this->admin, ['categories' => []], $product->fresh(), patch: true);
        $this->assertSame([], $product->categories()->pluck('taxonomies.id')->all());

        $restored = app(EditorialOperations::class)->restore($this->admin, $product, $withShirts, $history->fingerprint($product));
        $this->assertSame([$this->shirts->id], $restored->categories()->pluck('taxonomies.id')->all());
    }

    public function test_mcp_creates_reads_replaces_and_removes_categories(): void
    {
        $this->withToken(McpApiKey::issue($this->admin, 'Categories', 'full', null));

        $created = $this->rpcData($this->rpc('products_create_draft', ['title' => 'Remera', 'slug' => 'remera', 'categories' => [$this->shirts->id]]))['data'];
        $this->assertSame([$this->shirts->id], $created['categories']);
        $this->assertSame([$this->shirts->id], $this->rpcData($this->rpc('products_get', ['product_id' => $created['id']]))['data']['categories']);

        $updated = $this->rpcData($this->rpc('products_update_draft', ['product_id' => $created['id'], 'expected_revision' => $created['revision'], 'categories' => [$this->clothes->id, $this->shirts->id]]))['data'];
        $this->assertSame([$this->clothes->id, $this->shirts->id], $updated['categories']);
        $this->assertNotSame($created['revision'], $updated['revision']);

        // A patch without the key leaves them; an empty list removes them.
        $titled = $this->rpcData($this->rpc('products_update', ['product_id' => $created['id'], 'expected_revision' => $updated['revision'], 'title' => 'Remera lisa']))['data'];
        $this->assertSame([$this->clothes->id, $this->shirts->id], $titled['categories']);
        $cleared = $this->rpcData($this->rpc('products_update', ['product_id' => $created['id'], 'expected_revision' => $titled['revision'], 'categories' => []]))['data'];
        $this->assertSame([], $cleared['categories']);
        $this->assertGreaterThanOrEqual(4, app(EditorialHistory::class)->query(Product::findOrFail($created['id']))->where('is_proposal', false)->count());

        $brand = Taxonomy::create(['locale' => 'es', 'name' => 'Acme', 'slug' => 'acme', 'type' => Product::BRAND_TAXONOMY]);
        $this->rpc('products_update', ['product_id' => $created['id'], 'expected_revision' => $cleared['revision'], 'categories' => [$brand->id]])
            ->assertOk()->assertJsonPath('error.code', -32602)->assertJsonPath('error.data', ['categories.0' => [__('shop.category_invalid')]]);
        $this->assertSame([], Product::findOrFail($created['id'])->categories()->pluck('taxonomies.id')->all());

        $schema = collect($this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk()->json('result.tools'))
            ->firstWhere('name', 'products_create_draft')['inputSchema']['properties'];
        $this->assertSame(['array', 'null'], $schema['categories']['type']);
    }

    public function test_meta_feed_sends_the_category_path_as_product_type_and_the_top_level_as_custom_label_0(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $operations = app(ProductOperations::class);
        $image = fn (string $name) => Media::create(['filename' => $name, 'original_filename' => $name, 'path' => 'media/2026/11/'.$name, 'mime_type' => 'image/jpeg',
            'extension' => 'jpg', 'size' => 1000, 'width' => 800, 'height' => 800, 'disk' => 'public'])->id;
        $operations->save($this->admin, ['title' => 'Remera', 'slug' => 'remera', 'sku' => 'REM', 'status' => 'published', 'price_amount' => 90000,
            'featured_image' => $image('remera.jpg'), 'categories' => [$this->shirts->id]]);
        $operations->save($this->admin, ['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'status' => 'published', 'price_amount' => 50000, 'featured_image' => $image('mate.jpg')]);
        $token = app(ConnectorOperations::class)->activate($this->admin, 'meta')->plainToken();

        $xml = simplexml_load_string($this->get('/feeds/meta/'.$token.'.xml')->assertOk()->streamedContent());
        $items = [];
        foreach ($xml->channel->item as $item) {
            $fields = [];
            foreach ($item->children(MetaFeedWriter::NAMESPACE_G) as $name => $value) {
                $fields[$name] = (string) $value;
            }
            $items[$fields['id']] = $fields;
        }

        $this->assertSame('Ropa > Remeras', $items['REM']['product_type']);
        $this->assertSame('Ropa', $items['REM']['custom_label_0']);
        $this->assertArrayNotHasKey('product_type', $items['MATE']);
        $this->assertArrayNotHasKey('custom_label_0', $items['MATE']);
    }

    private function rpc(string $tool, array $args = []): TestResponse
    {
        return $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]);
    }

    private function rpcData(TestResponse $response): array
    {
        $response->assertOk()->assertJsonMissingPath('error');
        $this->assertFalse($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function form(array $fields): array
    {
        return $fields + ['has_variants' => '0', 'track_stock' => '0', 'allow_backorder' => '0', 'locale' => 'es'];
    }
}
