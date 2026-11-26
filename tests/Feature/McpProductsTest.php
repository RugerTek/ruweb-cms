<?php

namespace Tests\Feature;

use App\Http\Controllers\McpServerController;
use App\Http\Middleware\EnforceMcpScopes;
use App\Mcp\ToolRegistry;
use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\ProductOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** products_* MCP tools and REST mirror of the optional shop catalog (PLAN.md stage 1, track 1D). */
class McpProductsTest extends TestCase
{
    use RefreshDatabase;

    private const TOOLS = ['products_list', 'products_get', 'products_create_draft', 'products_update_draft', 'products_update', 'products_publish', 'products_delete'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['es', 'en'] as $locale) {
            Locale::create(['code' => $locale, 'name' => $locale, 'is_base' => $locale === 'es', 'is_active' => true, 'is_public' => true]);
        }
        $this->useShop('catalog');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->as($this->admin);
    }

    private function as(User $user, string $scope = 'full'): void
    {
        $this->withToken(McpApiKey::issue($user, 'Products '.$scope, $scope, null));
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

    private function toolNames(): array
    {
        return array_column($this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk()->json('result.tools'), 'name');
    }

    private function revision(int $id): string
    {
        return $this->rpcData($this->rpc('products_get', ['product_id' => $id]))['data']['revision'];
    }

    private function product(string $slug, string $status = 'draft'): Product
    {
        return app(ProductOperations::class)->save($this->admin, ['title' => ucfirst($slug), 'slug' => $slug, 'status' => $status, 'price_amount' => 1000]);
    }

    public function test_the_catalog_surface_follows_the_mcp_contract_only_while_it_is_on(): void
    {
        $tools = $this->toolNames();
        foreach (self::TOOLS as $tool) {
            $this->assertContains($tool, $tools);
        }
        $resolve = new \ReflectionMethod(McpServerController::class, 'resolveHandler');
        $this->assertSame([], array_values(array_filter(self::TOOLS, fn ($tool) => $resolve->invoke(app(McpServerController::class), $tool) === null)));
        $this->assertSame(['products_list', 'products_get'], array_values(array_intersect(self::TOOLS, EnforceMcpScopes::reads())));
        $this->assertSame('products', config('cms.collections.products.mcp_operations'));
        $doc = (string) file_get_contents(base_path('docs/operations/mcp.md'));
        $this->assertSame([], array_values(array_filter(self::TOOLS, fn ($tool) => ! str_contains($doc, '`'.$tool.'`'))));
        $menuTypes = collect(ToolRegistry::all())->firstWhere('name', 'menus_sync_items')['inputSchema']['properties']['items']['items']['properties']['type']['enum'];
        $this->assertSame(['custom_link', 'page', 'post', 'taxonomy', 'product'], $menuTypes);
        $this->getJson('/mcp/products')->assertOk();

        $product = $this->product('mate');
        $this->useShop('off');
        $this->assertSame([], array_values(array_filter($this->toolNames(), fn ($tool) => str_starts_with($tool, 'products_'))));
        $this->assertSame([], array_values(array_intersect(self::TOOLS, EnforceMcpScopes::reads())));
        foreach (['products_list' => [], 'products_get' => ['product_id' => $product->id], 'products_create_draft' => ['title' => 'X', 'slug' => 'x']] as $tool => $args) {
            $this->rpc($tool, $args)->assertOk()->assertJsonPath('error.code', -32601);
        }
        $this->getJson('/mcp/products')->assertNotFound();
        $this->getJson('/mcp/products/'.$product->id)->assertNotFound();
        $this->postJson('/mcp/products/drafts', ['title' => 'X', 'slug' => 'x'])->assertNotFound();
        $menuTypes = collect(ToolRegistry::all())->firstWhere('name', 'menus_sync_items')['inputSchema']['properties']['items']['items']['properties']['type']['enum'];
        $this->assertNotContains('product', $menuTypes);
        $this->assertSame(1, Product::count());
    }

    public function test_an_administrator_creates_edits_publishes_and_deletes_a_product(): void
    {
        $created = $this->rpcData($this->rpc('products_create_draft', [
            'title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 150000, 'compare_at_price_amount' => 180000,
            'description' => '<p>Calabaza</p><script>x()</script>', 'meta_json' => ['title' => 'SEO', 'description' => 'Desc'],
        ]));
        $data = $created['data'];
        $this->assertSame(['draft', 'es', 'MATE', 150000, 180000, 'PYG', '/productos/mate'], [$data['status'], $data['locale'], $data['sku'], $data['price_amount'], $data['compare_at_price_amount'], $data['currency'], $data['url']]);
        $this->assertStringNotContainsString('<script', $data['description']);
        $this->assertSame('description', $created['warnings'][0]['field']);
        $this->assertSame(app(ProductOperations::class)->revision(Product::findOrFail($data['id'])), $data['revision']);

        $data = $this->rpcData($this->rpc('products_update_draft', ['product_id' => $data['id'], 'expected_revision' => $data['revision'],
            'has_variants' => true, 'variants' => [['name' => 'Rojo', 'sku' => 'MATE-R', 'price_amount' => 160000, 'attributes_json' => ['Color' => 'Rojo']], ['name' => 'Azul', 'price_amount' => 155000]],
            'meta_json' => ['description' => null]]))['data'];
        $this->assertSame(['Rojo', 'Azul'], array_column($data['variants'], 'name'));
        $this->assertSame(155000, $data['display_price_amount']);
        $this->assertSame(['title' => 'SEO'], $data['meta_json']);

        $data = $this->rpcData($this->rpc('products_publish', ['product_id' => $data['id'], 'expected_revision' => $data['revision']]))['data'];
        $this->assertSame('published', $data['status']);

        // Live edit keeps the publication; the variant list is replaced whole.
        $red = $data['variants'][0]['id'];
        $data = $this->rpcData($this->rpc('products_update', ['product_id' => $data['id'], 'expected_revision' => $data['revision'], 'title' => 'Mate imperial',
            'variants' => [['id' => $red, 'name' => 'Rojo', 'sku' => 'MATE-R', 'price_amount' => 170000]]]))['data'];
        $this->assertSame(['Mate imperial', 'published', [$red], 170000], [$data['title'], $data['status'], array_column($data['variants'], 'id'), $data['display_price_amount']]);

        $listed = $this->rpcData($this->rpc('products_list', ['status' => 'published', 'search' => 'MATE', 'fields' => ['title']]));
        $this->assertSame([['id' => $data['id'], 'revision' => $data['revision'], 'title' => 'Mate imperial']], $listed['data']);
        $this->getJson('/mcp/products/'.$data['id'])->assertOk()->assertJsonPath('data.revision', $data['revision']);

        $menu = Menu::create(['name' => 'Header', 'slug' => 'header', 'locale' => 'es']);
        MenuItem::create(['menu_id' => $menu->id, 'label' => 'Mate', 'type' => 'product', 'linkable_type' => Product::class, 'linkable_id' => $data['id'], 'target' => '_self', 'order' => 0]);
        $deleted = $this->rpcData($this->rpc('products_delete', ['product_id' => $data['id'], 'expected_revision' => $data['revision']]));
        $this->assertSame(['type' => 'products', 'id' => $data['id'], 'title' => 'Mate imperial', 'locale' => 'es', 'url' => '/productos/mate'], $deleted['data']['deleted']);
        $this->assertSame(['menu_items_removed'], array_column($deleted['warnings'], 'code'));
        $this->assertNull(Product::find($data['id']));
        $this->assertSame(0, MenuItem::count());
        $this->rpc('products_get', ['product_id' => $data['id']])->assertOk()->assertJsonPath('error.message', 'Resource not found.');
    }

    public function test_stale_revisions_are_conflicts_and_a_variant_change_makes_a_revision_stale(): void
    {
        $product = $this->product('mate');
        $old = $this->revision($product->id);
        $this->rpcData($this->rpc('products_update_draft', ['product_id' => $product->id, 'expected_revision' => $old, 'has_variants' => true, 'variants' => [['name' => 'Rojo']]]));
        $current = $this->revision($product->id);
        $this->assertNotSame($old, $current);

        $calls = [
            'products_update_draft' => ['title' => 'Stale draft'],
            'products_update' => ['title' => 'Stale live'],
            'products_publish' => [],
            'products_delete' => [],
        ];
        foreach ($calls as $tool => $args) {
            $this->rpc($tool, ['product_id' => $product->id, 'expected_revision' => $old] + $args)->assertOk()->assertJsonPath('error.code', -32009);
        }
        $this->patchJson('/mcp/products/'.$product->id, ['expected_revision' => $old, 'title' => 'REST stale'])->assertStatus(409);
        $this->assertSame(['Mate', 'draft'], [$product->fresh()->title, $product->fresh()->status]);
        $this->assertSame($current, $this->revision($product->id));
    }

    public function test_invalid_input_is_a_validation_error_naming_the_field(): void
    {
        $this->rpc('products_create_draft', ['title' => 'Mate'])->assertOk()->assertJsonPath('error.code', -32602)->assertJsonPath('error.data.slug.0', 'The slug field is required.');
        $this->rpc('products_create_draft', ['title' => 'Mate', 'slug' => 'mate', 'price_amount' => '150.000'])->assertOk()->assertJsonPath('error.code', -32602);
        $response = $this->rpc('products_create_draft', ['title' => 'Mate', 'slug' => 'mate', 'price_amount' => 200, 'compare_at_price_amount' => 100])->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertStringContainsString('compare_at_price_amount', $response->json('error.message'));
        $this->rpc('products_create_draft', ['title' => 'Mate', 'slug' => 'mate', 'has_variants' => true])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('products_create_draft', ['title' => 'Mate', 'slug' => 'mate', 'locale' => 'en', 'sku' => 'EN'])->assertOk()->assertJsonPath('error.code', -32602)
            ->assertJsonPath('error.data.sku.0', __('shop.commerce_fields_base_only'));
        $this->assertSame(0, Product::count());

        $product = $this->product('mate');
        $this->rpc('products_create_draft', ['title' => 'Otro', 'slug' => 'mate'])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('products_update', ['product_id' => $product->id, 'title' => 'No revision'])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('products_delete', ['product_id' => $product->id])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('products_get', ['product_id' => $product->id + 100])->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertNotNull($product->fresh());
    }

    public function test_operation_key_makes_creation_retries_idempotent(): void
    {
        $input = ['title' => 'Mate', 'slug' => 'mate', 'price_amount' => 1000, 'operation_key' => 'create-mate-1'];
        $first = $this->rpcData($this->rpc('products_create_draft', $input))['data'];
        $second = $this->rpcData($this->rpc('products_create_draft', $input))['data'];
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, Product::count());

        $response = $this->rpc('products_create_draft', ['price_amount' => 2000] + $input)->assertOk()->assertJsonPath('error.code', -32009);
        $this->assertStringContainsString('Operation key already used', $response->json('error.message'));
        $this->assertSame(1, Product::count());
    }

    public function test_draft_tools_only_touch_drafts(): void
    {
        $published = $this->product('publicado', 'published');
        $this->rpc('products_update_draft', ['product_id' => $published->id, 'expected_revision' => $this->revision($published->id), 'title' => 'Cambio'])
            ->assertOk()->assertJsonPath('error.code', -32003)->assertJsonPath('error.message', __('editorial.mcp_draft_required'));
        $this->assertSame(['Publicado', 'published'], [$published->fresh()->title, $published->fresh()->status]);

        // create_draft never publishes, even when asked to.
        $data = $this->rpcData($this->rpc('products_create_draft', ['title' => 'Nuevo', 'slug' => 'nuevo']))['data'];
        $this->assertSame('draft', $data['status']);
        $this->rpcData($this->rpc('products_update_draft', ['product_id' => $data['id'], 'title' => 'Nuevo 2']));
        $this->assertSame('draft', Product::findOrFail($data['id'])->status);
    }

    public function test_editors_cannot_publish_delete_or_edit_published_products_live(): void
    {
        $draft = $this->product('borrador');
        $published = $this->product('publicado', 'published');
        $this->as(User::factory()->create(['role' => 'editor']));

        $created = $this->rpcData($this->rpc('products_create_draft', ['title' => 'Del editor', 'slug' => 'del-editor']))['data'];
        $this->rpcData($this->rpc('products_update', ['product_id' => $draft->id, 'expected_revision' => $this->revision($draft->id), 'title' => 'Borrador editado']));
        $this->rpc('products_publish', ['product_id' => $created['id'], 'expected_revision' => $created['revision']])->assertOk()->assertJsonPath('error.code', -32003)
            ->assertJsonPath('error.message', __('editorial.publish_forbidden'));
        $this->rpc('products_update', ['product_id' => $published->id, 'expected_revision' => $this->revision($published->id), 'title' => 'Live'])->assertOk()
            ->assertJsonPath('error.code', -32003)->assertJsonPath('error.message', __('editorial.mcp_live_edit_admin_only'));
        $this->rpc('products_delete', ['product_id' => $draft->id, 'expected_revision' => $this->revision($draft->id)])->assertOk()->assertJsonPath('error.code', -32003);
        $this->deleteJson('/mcp/products/'.$draft->id, ['expected_revision' => $this->revision($draft->id)])->assertForbidden();

        $this->assertSame(['Borrador editado', 'draft'], [$draft->fresh()->title, $draft->fresh()->status]);
        $this->assertSame(['Publicado', 'published'], [$published->fresh()->title, $published->fresh()->status]);
        $this->assertSame('draft', Product::findOrFail($created['id'])->status);
    }

    public function test_restricted_credentials_read_and_draft_only_within_their_scope(): void
    {
        $draft = $this->product('borrador');
        $published = $this->product('publicado', 'published');
        $revisions = [$draft->id => $this->revision($draft->id), $published->id => $this->revision($published->id)];

        $this->as($this->admin, 'read-only');
        $this->assertCount(2, $this->rpcData($this->rpc('products_list'))['data']);
        $this->rpcData($this->rpc('products_get', ['product_id' => $draft->id]));
        $this->getJson('/mcp/products/'.$draft->id)->assertOk();
        foreach (['products_create_draft' => ['title' => 'X', 'slug' => 'x'], 'products_update_draft' => ['product_id' => $draft->id, 'title' => 'X']] as $tool => $args) {
            $this->rpc($tool, $args)->assertOk()->assertJsonPath('error.code', -32003)->assertJsonPath('error.data.reason', 'scope_denied');
        }
        $this->postJson('/mcp/products/drafts', ['title' => 'X', 'slug' => 'x'])->assertForbidden();

        $this->as($this->admin, 'draft-only');
        $this->rpcData($this->rpc('products_list'));
        $created = $this->rpcData($this->rpc('products_create_draft', ['title' => 'Agente', 'slug' => 'agente']))['data'];
        $this->rpcData($this->rpc('products_update_draft', ['product_id' => $draft->id, 'expected_revision' => $revisions[$draft->id], 'title' => 'Borrador agente']));
        $this->postJson('/mcp/products/drafts', ['title' => 'REST', 'slug' => 'rest'])->assertCreated();
        $this->patchJson('/mcp/products/'.$created['id'].'/draft', ['title' => 'Agente REST'])->assertOk();
        $this->rpc('products_update_draft', ['product_id' => $published->id, 'title' => 'X'])->assertOk()->assertJsonPath('error.code', -32003)->assertJsonPath('error.data.reason', 'scope_denied');
        $this->patchJson('/mcp/products/'.$published->id.'/draft', ['title' => 'X'])->assertForbidden();
        foreach (['products_update', 'products_publish', 'products_delete'] as $tool) {
            $this->rpc($tool, ['product_id' => $draft->id, 'expected_revision' => $this->revision($draft->id)])->assertOk()->assertJsonPath('error.code', -32003);
        }
        $this->patchJson('/mcp/products/'.$draft->id, ['expected_revision' => $this->revision($draft->id), 'title' => 'X'])->assertForbidden();
        $this->postJson('/mcp/products/'.$draft->id.'/publish')->assertForbidden();
        $this->deleteJson('/mcp/products/'.$draft->id, ['expected_revision' => $this->revision($draft->id)])->assertForbidden();

        $this->assertSame(['Borrador agente', 'draft'], [$draft->fresh()->title, $draft->fresh()->status]);
        $this->assertSame(['Publicado', 'published'], [$published->fresh()->title, $published->fresh()->status]);
        $this->assertSame('Agente REST', Product::findOrFail($created['id'])->title);
        $this->assertSame(4, Product::count());
    }

    public function test_menus_link_products_through_mcp_only_while_the_catalog_is_on(): void
    {
        $product = $this->product('mate', 'published');
        $menu = $this->rpcData($this->rpc('menus_create', ['name' => 'Header', 'slug' => 'header', 'locale' => 'es']))['data'];
        $item = ['id' => 1, 'label' => 'Mate', 'type' => 'product', 'linkable_id' => $product->id, 'target' => '_self', 'order' => 0];

        $synced = $this->rpcData($this->rpc('menus_sync_items', ['menu_id' => $menu['id'], 'expected_revision' => $menu['revision'], 'items' => [$item]]))['data'];
        $this->assertSame(['product', $product->id, Product::class], [$synced['items'][0]['type'], $synced['items'][0]['linkable_id'], $synced['items'][0]['linkable_type']]);
        $this->putJson('/mcp/menus/'.$menu['id'].'/items', ['expected_revision' => $synced['revision'], 'items' => [['label' => 'REST'] + $item]])->assertOk()->assertJsonPath('data.items.0.label', 'REST');

        // Catalog off: re-sending the stored product item keeps it; adding another one is refused.
        $this->useShop('off');
        $kept = $this->rpcData($this->rpc('menus_sync_items', ['menu_id' => $menu['id'], 'items' => [['label' => 'Guardado'] + $item]]))['data'];
        $this->assertSame(['product', $product->id, Product::class], [$kept['items'][0]['type'], $kept['items'][0]['linkable_id'], $kept['items'][0]['linkable_type']]);
        $this->rpc('menus_sync_items', ['menu_id' => $menu['id'], 'items' => [$item, ['id' => 2, 'order' => 1] + $item]])->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertSame(['Guardado'], MenuItem::query()->pluck('label')->all());
    }
}
