<?php

namespace Tests\Feature;

use App\Exceptions\RevisionConflict;
use App\Models\Locale;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Taxonomy;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use App\Services\Products\ProductOperations;
use App\Services\Products\ProductPreview;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Shared product operations of the optional shop module (PLAN.md, stage 1 track 1A). */
class ProductOperationsTest extends TestCase
{
    use RefreshDatabase;

    private ProductOperations $operations;

    private User $admin;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useShop('catalog');
        $this->operations = app(ProductOperations::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
    }

    public function test_policy_is_discovered_and_mirrors_posts(): void
    {
        $this->assertInstanceOf(ProductPolicy::class, Gate::getPolicyFor(Product::class));
        $product = Product::create(['title' => 'Mate', 'slug' => 'mate']);
        $this->assertTrue(Gate::forUser($this->editor)->allows('update', $product));
        $this->assertFalse(Gate::forUser($this->editor)->allows('delete', $product));
        $this->assertTrue(Gate::forUser($this->admin)->allows('delete', $product));
        $this->assertFalse(Gate::forUser(User::factory()->create(['role' => 'viewer']))->allows('create', Product::class));
    }

    public function test_admin_creates_edits_publishes_unpublishes_and_deletes_with_an_explicit_actor(): void
    {
        $session = User::factory()->create(['role' => 'editor']);
        $this->actingAs($session);
        $brand = Taxonomy::create(['name' => 'Acme', 'slug' => 'acme', 'type' => 'brand']);
        $image = $this->media('mate.webp');

        $product = $this->operations->save($this->admin, [
            'title' => 'Mate', 'slug' => 'mate', 'status' => 'draft', 'sku' => 'MATE', 'price_amount' => 150000,
            'compare_at_price_amount' => '180000', 'description' => '<p>Calabaza</p><script>x()</script>',
            'brands' => [$brand->id], 'featured_image' => $image->id, 'gallery' => [$image->id],
        ]);

        $this->assertSame('draft', $product->status);
        $this->assertSame('es', $product->locale);
        $this->assertSame($this->admin->id, $product->created_by);
        $this->assertSame(150000, $product->price_amount->amount);
        $this->assertSame(180000, $product->compare_at_price_amount->amount);
        $this->assertStringNotContainsString('<script', $product->description);
        $this->assertSame([$brand->id], $product->brands()->pluck('taxonomies.id')->all());
        $this->assertSame($image->id, $product->featuredImage()?->id);
        $this->assertSame(1, $product->mediaByCollection('gallery')->count());
        $this->assertSame($session->id, EditorialActor::current()->id);

        // A full save is the whole form: omitted optional fields and relations are emptied.
        $product = $this->operations->save($this->admin, ['title' => 'Mate imperial', 'slug' => 'mate', 'status' => 'draft', 'sku' => 'MATE', 'expected_revision' => $this->operations->revision($product)], $product);
        $this->assertSame('Mate imperial', $product->title);
        $this->assertNull($product->price_amount);
        $this->assertNull($product->description);
        $this->assertSame(0, $product->brands()->count());
        $this->assertSame(0, $product->media()->count());

        $product = $this->operations->publish($this->admin, $product, $this->operations->revision($product));
        $this->assertSame('published', $product->status);
        $this->assertNotNull($product->published_at);
        $this->assertTrue(Product::published()->whereKey($product->id)->exists());

        $product = $this->operations->unpublish($this->admin, $product);
        $this->assertSame('draft', $product->status);

        $this->assertSame([$this->admin->id], DB::table('editorial_versions')->where('content_type', 'products')->where('content_id', $product->id)->distinct()->pluck('user_id')->all());
        $this->assertSame([$this->admin->id], DB::table('content_revisions')->where('entity_type', Product::class)->where('entity_id', $product->id)->distinct()->pluck('user_id')->all());

        $this->assertSame([], $this->operations->delete($this->admin, $product, $this->operations->revision($product)));
        $this->assertSame(0, Product::count());
    }

    public function test_price_is_optional_integer_and_compare_at_must_exceed_it(): void
    {
        $catalogOnly = $this->operations->save($this->admin, ['title' => 'A consultar', 'slug' => 'a-consultar', 'status' => 'published']);
        $this->assertNull($catalogOnly->price_amount);
        $this->assertNull($catalogOnly->displayPrice());
        $this->assertSame('published', $catalogOnly->status);

        foreach ([
            'price_amount' => ['price_amount' => '12.5'],
            'compare_at_price_amount' => ['price_amount' => 1000, 'compare_at_price_amount' => 1000],
            'price_amount ' => ['compare_at_price_amount' => 1000],
            'stock' => ['track_stock' => true],
        ] as $field => $input) {
            try {
                $this->operations->save($this->admin, ['title' => 'Bad', 'slug' => 'bad', 'status' => 'draft'] + $input);
                $this->fail("Invalid {$field} must fail.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(trim($field), $exception->errors());
            }
        }
        $this->assertSame(1, Product::count());
    }

    public function test_editor_saves_drafts_but_never_publishes_or_deletes(): void
    {
        $draft = $this->operations->save($this->editor, ['title' => 'Borrador', 'slug' => 'borrador', 'status' => 'draft']);
        $this->assertSame($this->editor->id, $draft->created_by);

        foreach ([
            fn () => $this->operations->save($this->editor, ['title' => 'Directo', 'slug' => 'directo', 'status' => 'published']),
            fn () => $this->operations->save($this->editor, ['title' => 'Borrador', 'slug' => 'borrador', 'status' => 'published'], $draft),
            fn () => $this->operations->publish($this->editor, $draft),
            fn () => $this->operations->schedule($this->editor, $draft, now()->addDay()->toIso8601String()),
            fn () => $this->operations->delete($this->editor, $draft),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('An editor published or deleted a product.');
            } catch (AuthorizationException) {
            }
        }
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertSame(1, Product::count());
    }

    public function test_editor_changes_to_a_live_product_stage_a_proposal(): void
    {
        $live = $this->operations->save($this->admin, ['title' => 'Vivo', 'slug' => 'vivo', 'status' => 'published', 'price_amount' => 1000]);

        $this->operations->save($this->editor, ['title' => 'Propuesta', 'slug' => 'vivo', 'status' => 'published', 'price_amount' => 2000], $live);

        $this->assertSame('Vivo', $live->fresh()->title);
        $this->assertSame(1000, $live->fresh()->price_amount->amount);
        $row = DB::table('editorial_versions')->where('content_type', 'products')->where('content_id', $live->id)->where('is_proposal', true)->first();
        $this->assertSame($this->editor->id, $row->user_id);
        $payload = json_decode($row->payload, true);
        $this->assertSame('Propuesta', $payload['attributes']['title']);
        $this->assertSame('published', $payload['attributes']['status']);

        $this->expectException(AuthorizationException::class);
        $this->operations->save($this->editor, ['title' => 'Parche'], $live, patch: true);
    }

    public function test_patch_keeps_omitted_fields_and_draft_scope_only_touches_drafts(): void
    {
        $product = $this->operations->save($this->admin, ['title' => 'Patch', 'slug' => 'patch', 'description' => '<p>Keep</p>', 'price_amount' => 5000, 'meta_json' => ['title' => 'SEO', 'description' => 'Old']], draftOnly: true);
        $this->assertSame('draft', $product->status);

        $product = $this->operations->save($this->admin, ['meta_json' => ['description' => null], 'price_amount' => 6000], $product, draftOnly: true);
        $this->assertSame(['title' => 'SEO'], $product->meta_json);
        $this->assertSame('<p>Keep</p>', $product->description);
        $this->assertSame(6000, $product->price_amount->amount);

        // One price in a patch is checked against the stored other one.
        $product = $this->operations->save($this->admin, ['compare_at_price_amount' => 7000], $product, patch: true);
        $this->assertSame(7000, $product->compare_at_price_amount->amount);
        $this->assertSame(6000, $product->price_amount->amount);
        try {
            $this->operations->save($this->admin, ['price_amount' => 8000], $product, patch: true);
            $this->fail('A price above the stored compare-at price must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('shop.compare_at_price_greater')], $exception->errors()['compare_at_price_amount']);
        }

        $product = $this->operations->publish($this->admin, $product);
        $patched = $this->operations->save($this->admin, ['title' => 'Patched live'], $product, patch: true);
        $this->assertSame('published', $patched->status);
        $this->assertSame('Patched live', $patched->title);

        $this->expectException(AuthorizationException::class);
        $this->operations->save($this->admin, ['title' => 'Bypass'], $patched, draftOnly: true);
    }

    public function test_stale_revision_conflicts_including_variant_only_changes(): void
    {
        $product = $this->operations->save($this->admin, ['title' => 'Remera', 'slug' => 'remera', 'status' => 'draft', 'has_variants' => true, 'variants' => [['name' => 'S', 'sku' => 'REM-S']]]);
        $stale = $this->operations->revision($product);
        $this->assertSame($stale, $this->operations->revision($product->fresh()));

        $product = $this->operations->save($this->admin, ['variants' => [['id' => $product->variants[0]->id, 'name' => 'Small', 'sku' => 'REM-S']], 'expected_revision' => $stale], $product, patch: true);
        $this->assertNotSame($stale, $this->operations->revision($product));

        foreach ([
            fn () => $this->operations->save($this->admin, ['title' => 'Perdido', 'expected_revision' => $stale], $product, patch: true),
            fn () => $this->operations->publish($this->admin, $product, $stale),
            fn () => $this->operations->delete($this->admin, $product, $stale),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A stale revision must conflict.');
            } catch (RevisionConflict $exception) {
                $this->assertSame(409, $exception->getStatusCode());
                $this->assertSame($this->operations->revision($product), $exception->current);
            }
        }
        $this->assertSame('Remera', $product->fresh()->title);
        $this->assertSame('draft', $product->fresh()->status);
    }

    public function test_variants_replace_the_whole_list(): void
    {
        $product = $this->operations->save($this->admin, ['title' => 'Remera', 'slug' => 'remera', 'status' => 'draft', 'sku' => 'REM', 'has_variants' => true, 'track_stock' => true, 'variants' => [
            ['name' => 'S', 'sku' => 'REM-S', 'price_amount' => 90000, 'stock' => 3, 'attributes_json' => ['size' => 'S']],
            ['name' => 'M', 'sku' => 'REM-M', 'price_amount' => 95000, 'stock' => 0],
            ['name' => 'L', 'sku' => 'REM-L', 'stock' => 1],
        ]]);
        [$small, $medium, $large] = $product->variants->all();
        $this->assertSame([0, 1, 2], $product->variants->pluck('order')->all());
        $this->assertSame(90000, $product->displayPrice()->amount);

        // Kept rows keep their id (and may swap skus), new rows are created, missing rows are deleted.
        $product = $this->operations->save($this->admin, ['variants' => [
            ['id' => $large->id, 'name' => 'L', 'sku' => 'REM-S', 'stock' => 1],
            ['name' => 'XL', 'sku' => 'REM-XL', 'price_amount' => 99000, 'stock' => 2],
            ['id' => $small->id, 'name' => 'S', 'sku' => 'REM-L', 'price_amount' => 91000, 'compare_at_price_amount' => 99000, 'stock' => 4],
        ]], $product, patch: true);
        $this->assertSame([$large->id, $product->variants[1]->id, $small->id], $product->variants->pluck('id')->all());
        $this->assertSame(['REM-S', 'REM-XL', 'REM-L'], $product->variants->pluck('sku')->all());
        $this->assertNull(ProductVariant::find($medium->id));
        $this->assertNull($product->variants[2]->attributes_json);
        $this->assertSame(99000, $product->variants[2]->compare_at_price_amount->amount);

        // Omitting the list in a patch leaves it alone; a stock-tracked variant needs stock.
        $product = $this->operations->save($this->admin, ['title' => 'Remera lisa'], $product, patch: true);
        $this->assertSame(3, $product->variants()->count());
        try {
            $this->operations->save($this->admin, ['variants' => [['name' => 'Sin stock']]], $product, patch: true);
            $this->fail('Tracked stock requires stock per variant.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('variants.0.stock', $exception->errors());
        }
        try {
            $this->operations->save($this->admin, ['variants' => []], $product, patch: true);
            $this->fail('A product with variants needs at least one.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('shop.variant_required')], $exception->errors()['variants']);
        }
        try {
            $this->operations->save($this->admin, ['variants' => [['id' => 999999, 'name' => 'Ajena', 'stock' => 1]]], $product, patch: true);
            $this->fail('A variant id of another product must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('variants.0.id', $exception->errors());
        }
        $this->assertSame(3, $product->variants()->count());

        // Turning variants off makes it a simple product again; a simple product cannot carry a list.
        $product = $this->operations->save($this->admin, ['has_variants' => false, 'track_stock' => false], $product, patch: true);
        $this->assertFalse($product->has_variants);
        $this->assertSame(0, ProductVariant::count());
        $this->expectException(ValidationException::class);
        $this->operations->save($this->admin, ['variants' => [['name' => 'Suelta']]], $product, patch: true);
    }

    public function test_sku_is_unique_across_products_and_variants(): void
    {
        $this->operations->save($this->admin, ['title' => 'Uno', 'slug' => 'uno', 'status' => 'draft', 'sku' => 'A1', 'has_variants' => true, 'variants' => [['name' => 'V', 'sku' => 'V1']]]);

        foreach ([
            'sku' => ['sku' => 'V1'],
            'variants.0.sku' => ['has_variants' => true, 'variants' => [['name' => 'V', 'sku' => 'A1']]],
            'variants.1.sku' => ['has_variants' => true, 'variants' => [['name' => 'V', 'sku' => 'NEW'], ['name' => 'W', 'sku' => 'NEW']]],
            'variants.0.sku ' => ['sku' => 'OWN', 'has_variants' => true, 'variants' => [['name' => 'V', 'sku' => 'OWN']]],
            'sku ' => ['sku' => 'A1'],
        ] as $field => $input) {
            try {
                $this->operations->save($this->admin, ['title' => 'Dos', 'slug' => 'dos', 'status' => 'draft'] + $input);
                $this->fail("Duplicated sku must fail on {$field}.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(trim($field), $exception->errors());
                $this->assertContains(__('shop.sku_unique'), $exception->errors()[trim($field)]);
            }
        }
        $this->assertSame(1, Product::count());
        $this->assertSame(1, ProductVariant::count());

        // Skus without a value are allowed many times (catalog items with no sku).
        $this->operations->save($this->admin, ['title' => 'Tres', 'slug' => 'tres', 'status' => 'draft', 'has_variants' => true, 'variants' => [['name' => 'X'], ['name' => 'Y']]]);
        $this->assertSame(3, ProductVariant::count());
    }

    public function test_translations_cannot_carry_commerce_fields(): void
    {
        foreach (['es', 'en'] as $code) {
            Locale::create(['code' => $code, 'name' => $code, 'is_base' => $code === 'es', 'is_active' => true]);
        }
        $translation = $this->operations->save($this->admin, ['title' => 'Gourd', 'slug' => 'gourd', 'status' => 'draft', 'locale' => 'en']);
        $this->assertSame('en', $translation->locale);

        try {
            $this->operations->save($this->admin, ['price_amount' => 1000, 'variants' => [['name' => 'Big']]], $translation, patch: true);
            $this->fail('A translation cannot set commerce fields.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('shop.commerce_fields_base_only')], $exception->errors()['price_amount']);
            $this->assertArrayHasKey('variants', $exception->errors());
        }
    }

    public function test_schedule_and_expiry_are_admin_publication_operations(): void
    {
        $product = $this->operations->save($this->admin, ['title' => 'Futuro', 'slug' => 'futuro', 'status' => 'draft']);
        $product = $this->operations->schedule($this->admin, $product, now()->addDay()->toIso8601String());
        $this->assertSame('published', $product->status);
        $this->assertFalse(Product::published()->whereKey($product->id)->exists());

        $product = $this->operations->setExpiry($this->admin, $product, now()->addDays(3)->toIso8601String());
        $this->assertNotNull($product->expires_at);
        $this->expectException(ValidationException::class);
        $this->operations->setExpiry($this->admin, $product, now()->subDay()->toIso8601String());
    }

    public function test_preview_never_writes_and_renders_the_proposal(): void
    {
        $product = $this->operations->save($this->admin, ['title' => 'Mate', 'slug' => 'mate', 'status' => 'published', 'price_amount' => 1000, 'has_variants' => true, 'variants' => [['name' => 'Chico', 'sku' => 'M-C', 'price_amount' => 1000]]]);
        $revision = $this->operations->revision($product);
        $versions = DB::table('editorial_versions')->count();
        $preview = app(ProductPreview::class);

        $proposal = $preview->proposal($product, ['title' => 'Mate nuevo', 'variants' => [['name' => 'Grande', 'sku' => 'M-G', 'price_amount' => 2000]]]);
        $this->assertSame('Mate nuevo', $proposal->title);
        $this->assertSame(['Grande'], $proposal->variants->pluck('name')->all());
        $this->assertSame(2000, $proposal->displayPrice()->amount);

        $payload = $preview->payload($product, ['title' => 'Formulario', 'slug' => 'mate'], patch: false);
        $this->assertSame('Formulario', $payload['attributes']['title']);
        $this->assertSame([], $payload['variants'], 'A whole form without has_variants is a simple product.');

        $views = sys_get_temp_dir().'/ruweb-product-preview-'.uniqid();
        File::ensureDirectoryExists($views.'/shop');
        File::put($views.'/shop/show.blade.php', '{{ $product->title }}|{{ $product->variants->pluck(\'name\')->join(\',\') }}|{{ $isEditorPreview ? \'preview\' : \'\' }}');
        View::getFinder()->prependLocation($views);
        View::getFinder()->flush();
        try {
            $response = $preview->render($this->editor, $product, ['title' => 'Mate nuevo', 'variants' => [['name' => 'Grande', 'sku' => 'M-G']]]);
        } finally {
            File::deleteDirectory($views);
        }
        $this->assertSame('Mate nuevo|Grande|preview', trim($response->getContent()));
        $this->assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));

        $this->assertSame('Mate', $product->fresh()->title);
        $this->assertSame(['Chico'], $product->fresh()->variants->pluck('name')->all());
        $this->assertSame($revision, $this->operations->revision($product->fresh()));
        $this->assertSame($versions, DB::table('editorial_versions')->count());
    }

    public function test_invalid_input_rolls_back_without_versions(): void
    {
        $product = $this->operations->save($this->admin, ['title' => 'Draft', 'slug' => 'draft', 'status' => 'draft']);
        $counts = [DB::table('editorial_versions')->count(), DB::table('content_revisions')->count()];
        try {
            $this->operations->save($this->admin, ['title' => 'Invalid', 'brands' => [99999]], $product, patch: true);
            $this->fail('Invalid taxonomy must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('brands.0', $exception->errors());
        }
        $this->assertSame('Draft', $product->fresh()->title);
        $this->assertSame($counts, [DB::table('editorial_versions')->count(), DB::table('content_revisions')->count()]);
        $this->assertNotSame('', app(EditorialHistory::class)->fingerprint($product));
    }

    private function media(string $name): Media
    {
        return Media::create([
            'filename' => $name, 'original_filename' => $name, 'path' => 'media/tests/'.$name, 'mime_type' => 'image/webp',
            'extension' => 'webp', 'size' => 1024, 'disk' => 'public', 'uploaded_by' => $this->admin->id,
        ]);
    }
}
