<?php

namespace Tests\Feature;

use App\Exceptions\RevisionConflict;
use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Media;
use App\Models\Product;
use App\Models\Taxonomy;
use App\Models\User;
use App\Services\Content\TranslationOperations;
use App\Services\Editorial\EditorialHistory;
use App\Services\Editorial\EditorialOperations;
use App\Services\Products\ProductOperations;
use App\Services\Products\ProductPreview;
use App\Support\EditorialTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Products as a full editorial type: history, restoration, proposals, shared previews, translation, content_* (track 1F). */
class ProductEditorialTest extends TestCase
{
    use RefreshDatabase;

    private ProductOperations $operations;

    private EditorialHistory $history;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useShop('catalog');
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true]);
        $this->operations = app(ProductOperations::class);
        $this->history = app(EditorialHistory::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
    }

    public function test_products_are_an_editorial_type_only_while_the_catalog_is_on(): void
    {
        $this->assertSame(Product::class, EditorialTypes::model('products'));
        $this->assertSame(['label' => 'Tienda', 'routes' => ['shop.index', 'localized.shop.index']], config('cms.custom_code.indexes.shop'));

        $this->useShop('off');
        $this->assertFalse(EditorialTypes::has('products'));
        $this->assertArrayNotHasKey('shop', config('cms.custom_code.indexes'));
        $this->assertArrayHasKey('blog', config('cms.custom_code.indexes'));
    }

    public function test_history_records_product_changes_including_variants_and_plain_prices(): void
    {
        $product = $this->variantProduct();
        $versions = $this->history->query($product)->where('is_proposal', false)->count();
        $revision = $this->operations->revision($product);
        $this->assertSame($this->history->fingerprint($product), $revision);

        $variants = $this->variantRows($product);
        $variants[0]['price_amount'] = 12500;
        $product = $this->operations->save($this->admin, ['variants' => $variants, 'expected_revision' => $revision], $product, patch: true);

        $this->assertNotSame($revision, $this->operations->revision($product));
        $this->assertGreaterThan($versions, $this->history->query($product)->where('is_proposal', false)->count());
        $payload = json_decode($this->history->query($product)->latest('id')->value('payload'), true);
        $this->assertSame(150000, $payload['attributes']['price_amount']);
        $this->assertSame([12500, 20000], array_column($payload['variants'], 'price_amount'));
        $this->assertSame(['Chico', 'Grande'], array_column($payload['variants'], 'name'));

        // A variant-only change makes the previous revision stale for every writer.
        $this->expectException(RevisionConflict::class);
        $this->operations->save($this->admin, ['title' => 'Stale', 'expected_revision' => $revision], $product, patch: true);
    }

    public function test_restoring_a_version_restores_money_prices_and_variants(): void
    {
        $product = $this->variantProduct();
        $version = (int) $this->history->query($product)->where('is_proposal', false)->latest('id')->value('id');
        $variants = $this->variantRows($product);
        $variants[0]['price_amount'] = 15000;
        $product = $this->operations->save($this->admin, [
            'price_amount' => 99000, 'compare_at_price_amount' => null, 'title' => 'Mate cambiado',
            'variants' => [$variants[0], ['name' => 'Mediano', 'sku' => 'MATE-M', 'price_amount' => 17000]],
        ], $product, patch: true);
        $this->assertSame(['Chico', 'Mediano'], $product->variants()->pluck('name')->all());

        $restored = app(EditorialOperations::class)->restore($this->admin, $product, $version, $this->history->fingerprint($product));

        $this->assertSame('Mate', $restored->title);
        $this->assertSame('draft', $restored->status);
        $this->assertSame(150000, $restored->price_amount->amount);
        $this->assertSame(180000, $restored->compare_at_price_amount->amount);
        $this->assertSame(['Chico', 'Grande'], $restored->variants()->pluck('name')->all());
        $this->assertSame([10000, 20000], $restored->variants->map(fn ($variant) => $variant->price_amount->amount)->all());
        $this->assertSame(['MATE-C', 'MATE-G'], $restored->variants->pluck('sku')->all());
        $this->assertSame($this->history->fingerprint($restored), $this->history->query($restored)->where('is_proposal', false)->latest('id')->value('fingerprint'));
    }

    public function test_versions_written_with_money_objects_still_restore(): void
    {
        $product = $this->operations->save($this->admin, ['title' => 'Bombilla', 'slug' => 'bombilla', 'status' => 'draft', 'price_amount' => 50000]);
        $payload = $this->history->payload($product);
        $payload['attributes']['price_amount'] = ['amount' => 45000, 'currency' => 'PYG'];
        $legacy = DB::table('editorial_versions')->insertGetId(['content_type' => 'products', 'content_id' => $product->id, 'user_id' => $this->admin->id,
            'payload' => json_encode($payload), 'fingerprint' => hash('sha256', 'legacy'), 'created_at' => now(), 'updated_at' => now()]);

        $restored = app(EditorialOperations::class)->restore($this->admin, $product, $legacy);

        $this->assertSame(45000, $restored->price_amount->amount);
    }

    public function test_editor_proposal_on_a_published_product_is_applied_or_discarded(): void
    {
        $product = $this->operations->publish($this->admin, $this->variantProduct());
        $variants = $this->variantRows($product);

        // Panel: an editor's full save of a live product is staged as a proposal, the product is not written.
        $this->operations->save($this->editor, ['title' => 'Mate propuesto', 'slug' => 'mate', 'status' => 'published', 'sku' => 'MATE',
            'price_amount' => 160000, 'has_variants' => true, 'variants' => [$variants[1], ['name' => 'Gigante', 'price_amount' => 30000]]], $product);
        $this->assertSame('Mate', $product->fresh()->title);
        $proposal = (int) $this->history->query($product)->where('is_proposal', true)->latest('id')->value('id');
        $this->assertGreaterThan(0, $proposal);

        $editorial = app(EditorialOperations::class);
        $review = $editorial->requestReview($this->editor, $product, $this->admin->id, $proposal);
        $editorial->decide($this->admin, $product->fresh(), $review->id, 'approved');

        $product = $product->fresh();
        $this->assertSame('Mate propuesto', $product->title);
        $this->assertSame('published', $product->status);
        $this->assertSame(160000, $product->price_amount->amount);
        $this->assertSame(['Grande', 'Gigante'], $product->variants()->pluck('name')->all());
        $this->assertSame($variants[1]['id'], $product->variants()->first()->id);

        // MCP: content_propose, then the administrator requests changes and the product stays as it was.
        $keys = $this->keys();
        $proposed = $this->withToken($keys['editor'])->postJson($this->endpoint($product, 'propose'), $this->base($product) + [
            'changes' => ['price_amount' => 1000, 'description' => '<p>Nueva</p>'], 'operation_key' => 'product-propose-1',
        ])->assertOk()->json('data.version');
        $this->withToken($keys['editor'])->postJson($this->endpoint($product, 'propose'), $this->base($product) + [
            'changes' => ['status' => 'draft'], 'operation_key' => 'product-propose-status',
        ])->assertUnprocessable();
        $review = $this->withToken($keys['editor'])->postJson($this->endpoint($product, 'review-request'), $this->base($product) + [
            'version_id' => $proposed['id'], 'reviewer_id' => $this->admin->id, 'operation_key' => 'product-review-1',
        ])->assertOk()->json('data.review');
        $this->withToken($keys['admin'])->postJson($this->endpoint($product, 'review-decide'), $this->base($product) + [
            'review_id' => $review['id'], 'state' => 'changes_requested', 'operation_key' => 'product-decide-1',
        ])->assertOk()->assertJsonPath('data.review.state', 'changes_requested');

        $this->assertSame(160000, $product->fresh()->price_amount->amount);
        $this->assertNull($product->fresh()->description);
    }

    public function test_shared_draft_preview_link_renders_the_pinned_product_and_is_revoked(): void
    {
        $product = $this->variantProduct();
        $preview = app(ProductPreview::class);

        $saved = $preview->link($this->admin, $product);
        $this->get(parse_url($saved['url'], PHP_URL_PATH))->assertOk()->assertSee('Mate')->assertSee('Grande')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        $variants = $this->variantRows($product);
        $shared = $preview->link($this->editor, $product, ['title' => 'Mate en vista previa', 'variants' => [$variants[0], ['name' => 'Edición limitada', 'price_amount' => 99000]]]);
        $path = parse_url($shared['url'], PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('Mate en vista previa')->assertSee('Edición limitada')->assertDontSee('Grande');
        $this->assertSame('Mate', $product->fresh()->title);
        $this->assertSame(2, $product->variants()->count());

        $revoked = $preview->revoke($this->editor, $product, $shared['link']['id']);
        $this->assertNotNull($revoked->revoked_at);
        $this->get($path)->assertNotFound();
    }

    public function test_translating_a_product_copies_translatable_fields_and_not_commerce_fields(): void
    {
        $brand = Taxonomy::create(['name' => 'Acme', 'slug' => 'acme', 'type' => 'brand', 'locale' => 'es']);
        $brandEn = Taxonomy::create(['name' => 'Acme', 'slug' => 'acme', 'type' => 'brand', 'locale' => 'en', 'translation_group_id' => $brand->translation_group_id]);
        $image = Media::create(['filename' => 'mate.webp', 'original_filename' => 'mate.webp', 'path' => 'media/tests/mate.webp', 'mime_type' => 'image/webp', 'extension' => 'webp', 'size' => 10, 'disk' => 'public']);
        $product = $this->variantProduct(['description' => '<p>Calabaza</p>', 'meta_json' => ['title' => 'Mate SEO'], 'brands' => [$brand->id], 'featured_image' => $image->id, 'allow_backorder' => true, 'weight_grams' => 300, 'dimensions_json' => ['length' => 10]]);

        $translation = app(TranslationOperations::class)->create($this->admin, $product, 'en');

        $this->assertSame('en', $translation->locale);
        $this->assertSame($product->translation_group_id, $translation->translation_group_id);
        $this->assertSame(['Mate', '<p>Calabaza</p>', 'Mate SEO'], [$translation->title, $translation->description, $translation->meta_json['title']]);
        $this->assertSame(['draft', 'needs_review'], [$translation->status, $translation->translation_status]);
        $this->assertNull($translation->sku);
        $this->assertNull($translation->price_amount);
        $this->assertNull($translation->compare_at_price_amount);
        $this->assertNull($translation->weight_grams);
        $this->assertNull($translation->dimensions_json);
        $this->assertFalse($translation->has_variants);
        $this->assertFalse($translation->allow_backorder);
        $this->assertSame(0, $translation->variants()->count());
        $this->assertSame(2, $product->variants()->count());
        $this->assertSame($image->id, $translation->featuredImage()?->id);
        $this->assertSame([$brandEn->id], $translation->brands()->pluck('taxonomies.id')->all());
        $this->assertSame($product->translatableFingerprint(), $translation->source_fingerprint);
        $this->assertNotNull($translation->source_fingerprint);
        $this->assertSame([], $translation->staleTranslatableFields());

        // A base change marks the translated field stale; MCP shows it and repeating the translation is idempotent.
        $this->operations->save($this->admin, ['title' => 'Mate imperial'], $product->fresh(), patch: true);
        $this->assertSame(['title'], $translation->fresh()->staleTranslatableFields());
        $keys = $this->keys();
        $this->withToken($keys['admin'])->getJson('/mcp/content/products/'.$translation->id.'/translation-changes')
            ->assertOk()->assertJsonPath('data.stale_fields', ['title'])->assertJsonPath('data.changes.title.after', 'Mate imperial');
        $this->withToken($keys['admin'])->postJson('/mcp/content/products/'.$product->id.'/translation', ['locale' => 'en'])
            ->assertOk()->assertJsonPath('data.id', $translation->id)->assertJsonPath('data.locale', 'en')->assertJsonPath('data.price_amount', null)->assertJsonPath('data.variants', []);
    }

    public function test_content_tools_accept_products(): void
    {
        $product = $this->variantProduct();
        $keys = $this->keys();

        $schema = $this->rpc($keys['admin'], 'cms_schema', ['type' => 'products']);
        $this->assertSame('products', $schema['type']);
        $this->assertTrue($schema['schema']['description']['translatable']);
        $this->assertSame('products', $this->rpc($keys['admin'], 'content_versions', ['type' => 'products', 'id' => $product->id])['versions'][0]['content_type']);

        $field = $this->withToken($keys['admin'])->getJson('/mcp/content/products/'.$product->id.'/field?field=title')->assertOk();
        $this->assertSame('Mate', $field->json('data.value'));
        $this->assertSame($this->operations->revision($product), $field->json('data.revision'));

        $at = now()->addDay()->startOfMinute();
        $this->withToken($keys['admin'])->postJson('/mcp/content/products/'.$product->id.'/publication', ['action' => 'schedule', 'published_at' => $at->toISOString(), 'expected_revision' => $this->operations->revision($product)])
            ->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.price_amount', 150000);
        $this->assertTrue($product->fresh()->published_at->equalTo($at));
        $this->withToken($keys['editor'])->postJson('/mcp/content/products/'.$product->id.'/publication', ['action' => 'unpublish'])->assertForbidden();

        $preview = $this->withToken($keys['editor'])->postJson('/mcp/content/products/'.$product->id.'/preview', ['hours' => 2])->assertOk();
        $this->get(parse_url($preview->json('data.url'), PHP_URL_PATH))->assertOk()->assertSee('Mate');

        $this->useShop('off');
        $this->withToken($keys['admin'])->getJson('/mcp/content/products/'.$product->id.'/versions')->assertNotFound();
        $this->withToken($keys['admin'])->postJson('/mcp/content/products/'.$product->id.'/translation', ['locale' => 'en'])->assertNotFound();
    }

    private function variantProduct(array $extra = []): Product
    {
        return $this->operations->save($this->admin, $extra + [
            'title' => 'Mate', 'slug' => 'mate', 'status' => 'draft', 'sku' => 'MATE', 'price_amount' => 150000,
            'compare_at_price_amount' => 180000, 'has_variants' => true, 'variants' => [
                ['name' => 'Chico', 'sku' => 'MATE-C', 'price_amount' => 10000],
                ['name' => 'Grande', 'sku' => 'MATE-G', 'price_amount' => 20000],
            ],
        ]);
    }

    private function variantRows(Product $product): array
    {
        return $product->variants()->get()->map(fn ($variant) => [
            'id' => $variant->id, 'name' => $variant->name, 'sku' => $variant->sku, 'price_amount' => $variant->price_amount?->amount,
        ])->all();
    }

    private function keys(): array
    {
        return [
            'admin' => McpApiKey::issue($this->admin, 'Shop admin', 'full', now()->addDay()),
            'editor' => McpApiKey::issue($this->editor, 'Shop editor', 'full', now()->addDay()),
        ];
    }

    private function base(Product $product): array
    {
        return ['expected_revision' => $this->history->fingerprint($product->fresh())];
    }

    private function endpoint(Product $product, string $action): string
    {
        return '/mcp/content/products/'.$product->id.'/'.$action;
    }

    private function rpc(string $key, string $tool, array $arguments): array
    {
        $response = $this->withToken($key)->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]])->assertOk();
        $this->assertNull($response->json('error'), $response->getContent());
        $this->assertFalse($response->json('result.isError'), $response->getContent());

        return json_decode($response->json('result.content.0.text'), true)['data'];
    }
}
