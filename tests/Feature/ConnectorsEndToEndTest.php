<?php

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\Meta\MetaFeedWriter;
use App\Models\Locale;
use App\Models\User;
use App\Services\Media\FeedImage;
use App\Services\Media\MediaOperations;
use App\Services\Products\ProductOperations;
use App\Support\ShopModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use SimpleXMLElement;
use Tests\TestCase;

/**
 * Stage 2 end to end (PLAN.md §5, stage 2 seam): with the shop off an administrator opens Connectors, turns the
 * catalog on from the Meta card, creates a product with a WebP image and variants through ProductOperations,
 * activates Meta, takes the feed URL from the panel, Meta downloads a valid XML with JPEG images, the panel shows
 * that download, and regenerating the URL makes the old one answer 404. Real connector, source and media pipeline.
 */
class ConnectorsEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private const META_AGENT = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('GD WebP support is not available.');
        }

        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Storage::fake('public');
        Storage::fake('local');
        $this->useShop('off');
    }

    public function test_meta_catalog_from_a_shop_that_is_off_to_a_feed_meta_downloads(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        // 1. Shop off: Connectors is there and the Meta card asks to turn the catalog on.
        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee('data-status="needs_action"', false)
            ->assertSee(__('connectors.meta_needs_catalog'))
            ->assertSee(route('admin.connectors.enable-catalog', ['connector' => 'meta']))
            ->assertDontSee(route('admin.connectors.activate', ['connector' => 'meta']));
        $this->assertFalse(app(ShopModule::class)->catalog());

        // 2. The card button turns the catalog on.
        $this->from(route('admin.connectors.index'))
            ->post(route('admin.connectors.enable-catalog', ['connector' => 'meta']))
            ->assertRedirect(route('admin.connectors.index'));
        $this->assertTrue(app(ShopModule::class)->catalog());

        // 3. A product with a WebP image (a JPEG upload the media pipeline stores as WebP) and two variants.
        $media = app(MediaOperations::class)->upload($admin, ['file' => UploadedFile::fake()->image('remera.jpg', 900, 900)])[0];
        $this->assertSame('webp', $media->extension);
        $this->assertSame('image/webp', $media->mime_type);
        $product = app(ProductOperations::class)->save($admin, [
            'title' => 'Remera Ñandutí', 'slug' => 'remera-nanduti', 'status' => 'published', 'sku' => 'REM',
            'description' => '<p>Algodón <strong>bordado</strong></p>', 'featured_image' => $media->id,
            'has_variants' => true, 'track_stock' => true, 'variants' => [
                ['name' => 'Roja M', 'sku' => 'REM-RM', 'price_amount' => 120000, 'stock' => 4, 'attributes_json' => ['color' => 'Rojo', 'talle' => 'M']],
                ['name' => 'Azul L', 'sku' => 'REM-AL', 'price_amount' => 125000, 'stock' => 0, 'attributes_json' => ['color' => 'Azul', 'talle' => 'L']],
            ],
        ]);
        $variants = $product->variants->keyBy('sku');

        // 4. The card is available; activating leads to the detail with the feed URL.
        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee('data-status="available"', false)
            ->assertSee(route('admin.connectors.activate', ['connector' => 'meta']));
        $this->post(route('admin.connectors.activate', ['connector' => 'meta']))
            ->assertRedirect(route('admin.connectors.show', ['connector' => 'meta']));

        $detail = $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk()
            ->assertSee('data-status="connected"', false)
            ->assertSee(__('connectors.meta_never_fetched'));
        $feedUrl = $this->feedUrlFrom($detail->getContent());
        $this->assertMatchesRegularExpression('#/feeds/meta/[A-Za-z0-9]{48}\.xml$#', $feedUrl);

        // 5. Meta downloads the feed: valid RSS with the product's variants and JPEG images.
        $response = $this->withHeaders(['User-Agent' => self::META_AGENT])->get($feedUrl)->assertOk();
        $this->assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
        $items = $this->items($response->streamedContent());

        $this->assertSame(['REM-RM', 'REM-AL'], array_keys($items));
        $red = $items['REM-RM'];
        $this->assertSame('REM', $red['item_group_id']);
        $this->assertSame('Remera Ñandutí - Roja M', $red['title']);
        $this->assertSame('Algodón bordado', $red['description']);
        $this->assertSame('120000 PYG', $red['price']);
        $this->assertSame('in stock', $red['availability']);
        $this->assertSame('new', $red['condition']);
        $this->assertSame(['Rojo', 'M'], [$red['color'], $red['size']]);
        $this->assertStringEndsWith('/productos/remera-nanduti?variante='.$variants['REM-RM']->id, $red['link']);
        $this->assertMatchesRegularExpression('#^https?://#', $red['link']);
        $this->assertSame('out of stock', $items['REM-AL']['availability']);
        $this->assertSame(['Azul', 'L'], [$items['REM-AL']['color'], $items['REM-AL']['size']]);

        $this->assertSame($red['image_link'], $items['REM-AL']['image_link']);
        $this->assertMatchesRegularExpression('#^https?://.+/feed/'.$media->id.'-[0-9a-f]{16}\.jpg$#', $red['image_link']);
        $derivatives = Storage::disk(FeedImage::DISK)->files(FeedImage::DIRECTORY);
        $this->assertCount(1, $derivatives);
        $this->assertStringEndsWith($derivatives[0], $red['image_link']);
        $this->assertSame('image/jpeg', getimagesize(Storage::disk(FeedImage::DISK)->path($derivatives[0]))['mime']);

        // 6. The panel shows Meta's download and the totals of the generated file.
        $record = app(ConnectorRegistry::class)->record('meta');
        $this->assertNotNull($record->last_meta_fetched_at);
        $this->assertSame(2, $record->last_item_count);
        $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk()
            ->assertDontSee(__('connectors.meta_never_fetched'))
            ->assertSee($record->last_meta_fetched_at->format('d/m/Y H:i'))
            ->assertSee(trans_choice('connectors.included_count', 2, ['count' => 2]));
        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertDontSee(__('connectors.meta_never_fetched'));

        // 7. Regenerating the URL: the old one is a 404 at once, the new one from the panel works.
        $this->post(route('admin.connectors.rotate', ['connector' => 'meta']))
            ->assertRedirect(route('admin.connectors.show', ['connector' => 'meta']));
        $newUrl = $this->feedUrlFrom($this->get(route('admin.connectors.show', ['connector' => 'meta']))->getContent());
        $this->assertNotSame($feedUrl, $newUrl);
        $this->withHeaders(['User-Agent' => self::META_AGENT])->get($feedUrl)->assertNotFound();
        $this->withHeaders(['User-Agent' => self::META_AGENT])->get($newUrl)->assertOk();
    }

    /** The feed URL as the detail screen shows it (the read-only input next to Copy). */
    private function feedUrlFrom(string $html): string
    {
        $this->assertSame(1, preg_match('#<input[^>]*id="feed-url"[^>]*value="([^"]+)"#', $html, $match), 'Feed URL not shown.');

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return array<string, array<string, string>> g: fields per item id, from a document that must be valid XML */
    private function items(string $body): array
    {
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $body);
        $xml = simplexml_load_string($body);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
        $this->assertSame('2.0', (string) $xml['version']);
        $items = [];
        foreach ($xml->channel->item as $item) {
            $fields = [];
            foreach ($item->children(MetaFeedWriter::NAMESPACE_G) as $name => $value) {
                $fields[$name] = (string) $value;
            }
            $items[$fields['id']] = $fields;
        }

        return $items;
    }
}
