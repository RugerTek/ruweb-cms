<?php

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\FeedWarning;
use App\Connectors\Meta\MetaCatalogConnector;
use App\Connectors\Meta\MetaFeedWriter;
use App\Connectors\Sources\ShopProductSource;
use App\Models\Connector;
use App\Models\Locale;
use App\Models\Media;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Connectors\FeedFile;
use App\Support\SiteUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use SimpleXMLElement;
use Tests\Support\FakeCatalogSource;
use Tests\TestCase;

/** Meta catalog feed: connector, XML writer, generated file and public URL (PLAN.md §4.5 and §4.7, stage 2, track 2B). */
class MetaFeedTest extends TestCase
{
    use RefreshDatabase;

    private const META_AGENT = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    protected function setUp(): void
    {
        parent::setUp();

        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Storage::fake('public');
        Storage::fake('local');
        $this->useShop('catalog');
    }

    public function test_meta_is_the_registered_feed_connector_with_its_defaults_and_guide_links(): void
    {
        $connector = app(ConnectorRegistry::class)->feed('meta');
        Setting::updateOrCreate(['key' => 'site_name'], ['value' => 'Tienda Ñandutí', 'type' => 'string', 'group' => 'general']);
        Setting::clearCache();

        $this->assertInstanceOf(MetaCatalogConnector::class, $connector);
        $this->assertSame('products', $connector->source());
        $this->assertSame('application/xml; charset=UTF-8', $connector->contentType());
        $this->assertSame('xml', $connector->extension());
        $this->assertSame(['facebookexternalhit'], $connector->fetchAgents());
        $this->assertSame(['brand' => 'Tienda Ñandutí', 'condition' => 'new', 'availability' => 'in stock', 'google_product_category' => ''], $connector->defaults());
        $this->assertSame('in:"new","refurbished","used"', (string) $connector->rules()['condition'][1]);
        $this->assertSame('in:"in stock","out of stock"', (string) $connector->rules()['availability'][1]);
        $this->assertSame('https://business.facebook.com/commerce_manager', MetaCatalogConnector::COMMERCE_MANAGER_URL);
        $this->assertStringStartsWith('https://', MetaCatalogConnector::HELP_SCHEDULED_FEEDS_URL);
        $this->assertStringStartsWith('https://', MetaCatalogConnector::HELP_TROUBLESHOOT_URL);
    }

    public function test_feed_is_valid_rss_with_the_g_namespace_one_field_per_line_and_the_nine_fields(): void
    {
        $source = $this->fakeSource();
        $image = $this->jpeg('portada.jpg');
        $source->items = [
            FakeCatalogSource::item('A-1', ['imageMediaId' => $image, 'title' => 'Mate & bombilla <gift>', 'description' => "Línea uno\nLínea dos"]),
            FakeCatalogSource::item('A-2', ['imageMediaId' => $image, 'brand' => null, 'availability' => null]),
        ];
        $token = $this->activate();

        $response = $this->feed($token)->assertOk();
        $body = $response->streamedContent();

        $this->assertSame('application/xml; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $body);
        $this->assertStringContainsString('xmlns:g="http://base.google.com/ns/1.0"', $body);
        $xml = simplexml_load_string($body);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
        $this->assertSame('2.0', (string) $xml['version']);
        $this->assertSame(SiteUrl::origin().'/', (string) $xml->channel->link);
        $this->assertNotSame('', (string) $xml->channel->title);

        $items = $this->items($body);
        $this->assertSame(['A-1', 'A-2'], array_keys($items));
        foreach ($items as $fields) {
            foreach (['id', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'brand'] as $field) {
                $this->assertNotSame('', $fields[$field] ?? '', 'missing g:'.$field);
            }
        }
        $this->assertSame('Mate & bombilla <gift>', $items['A-1']['title']);
        $this->assertSame("Línea uno\nLínea dos", $items['A-1']['description']);
        $this->assertSame('new', $items['A-1']['condition']);
        // Item without brand or availability takes the connector defaults.
        $this->assertSame('in stock', $items['A-2']['availability']);
        $this->assertSame(app(ConnectorRegistry::class)->settings('meta')['brand'], $items['A-2']['brand']);

        // One field per line: every line that opens a g: field closes it (multi-line text aside).
        foreach (explode("\n", $body) as $line) {
            if (preg_match('#^\s*<g:(\w+)>#', $line, $open) === 1 && $open[1] !== 'description') {
                $this->assertMatchesRegularExpression('#^\s*<g:(\w+)>.*</g:\1>$#', $line);
            }
            $this->assertLessThanOrEqual(1, preg_match_all('#<g:\w+>#', $line));
        }
    }

    public function test_prices_sale_price_variants_and_absolute_links(): void
    {
        $source = $this->fakeSource();
        $image = $this->jpeg('remera.jpg');
        $source->items = [
            FakeCatalogSource::item('SIMPLE', ['imageMediaId' => $image, 'price' => 150000]),
            FakeCatalogSource::item('OFERTA', ['imageMediaId' => $image, 'price' => 200000, 'salePrice' => 150000]),
            FakeCatalogSource::item('REM-R', ['imageMediaId' => $image, 'groupId' => 'REM', 'title' => 'Remera - Roja', 'url' => '/productos/remera?variante=5',
                'variantAttributes' => ['color' => 'Rojo', 'Talle' => 'M', 'Tela' => 'Algodón', 'gender' => 'Hombre', 'Corte' => 'Recto', 'Cuello' => 'V']]),
            FakeCatalogSource::item('v6', ['imageMediaId' => $image, 'groupId' => 'REM', 'title' => 'Remera - Azul', 'url' => '/productos/remera?variante=6',
                'variantAttributes' => ['Color' => 'Azul', 'gender' => 'Unisex']]),
        ];
        $token = $this->activate();

        $items = $this->items($this->feed($token)->assertOk()->streamedContent());

        $this->assertSame('150000 PYG', $items['SIMPLE']['price']);
        $this->assertArrayNotHasKey('sale_price', $items['SIMPLE']);
        $this->assertArrayNotHasKey('item_group_id', $items['SIMPLE']);
        $this->assertSame('200000 PYG', $items['OFERTA']['price']);
        $this->assertSame('150000 PYG', $items['OFERTA']['sale_price']);

        $this->assertSame('REM', $items['REM-R']['item_group_id']);
        $this->assertSame('REM', $items['v6']['item_group_id']);
        $this->assertNotSame($items['REM-R']['id'], $items['REM-R']['item_group_id']);
        $this->assertSame(SiteUrl::origin().'/productos/remera?variante=5', $items['REM-R']['link']);
        $this->assertSame(SiteUrl::origin().'/productos/item-SIMPLE', $items['SIMPLE']['link']);
        $this->assertSame(SiteUrl::origin().Storage::disk('public')->url('media/2026/11/remera.jpg'), $items['SIMPLE']['image_link']);
        foreach ($items as $fields) {
            $this->assertMatchesRegularExpression('#^https?://#', $fields['link']);
            $this->assertMatchesRegularExpression('#^https?://#', $fields['image_link']);
        }

        $this->assertSame('Rojo', $items['REM-R']['color']);
        $this->assertSame('M', $items['REM-R']['size']);
        $this->assertArrayNotHasKey('gender', $items['REM-R']); // "Hombre" is not a value Meta accepts
        $this->assertSame('Tela:Algodón,gender:Hombre,Corte:Recto', $items['REM-R']['additional_variant_attribute']);
        $this->assertSame('Azul', $items['v6']['color']);
        $this->assertSame('unisex', $items['v6']['gender']);
    }

    public function test_additional_images_fit_in_two_thousand_characters(): void
    {
        $source = $this->fakeSource();
        $gallery = [];
        for ($i = 1; $i <= 40; $i++) {
            $gallery[] = $this->jpeg('galeria-con-un-nombre-de-archivo-bastante-largo-para-llenar-el-campo-'.$i.'.jpg');
        }
        $source->items = [FakeCatalogSource::item('GAL', ['imageMediaId' => $this->jpeg('portada.jpg'), 'additionalImageMediaIds' => $gallery])];
        $token = $this->activate();

        $links = $this->items($this->feed($token)->assertOk()->streamedContent())['GAL']['additional_image_link'];

        $this->assertLessThanOrEqual(MetaFeedWriter::ADDITIONAL_IMAGE_LINK_MAX, strlen($links));
        $urls = explode(',', $links);
        $this->assertGreaterThan(5, count($urls));
        $this->assertLessThan(40, count($urls));
        $this->assertSame(SiteUrl::origin().Storage::disk('public')->url('media/2026/11/galeria-con-un-nombre-de-archivo-bastante-largo-para-llenar-el-campo-1.jpg'), $urls[0]);
    }

    public function test_long_titles_are_cut_with_a_notice_and_incomplete_items_are_skipped(): void
    {
        $source = $this->fakeSource();
        $image = $this->jpeg('portada.jpg');
        $small = $this->jpeg('chica.jpg', 300, 300);
        $gif = Media::create(['filename' => 'anim.gif', 'original_filename' => 'anim.gif', 'path' => 'media/2026/11/anim.gif', 'mime_type' => 'image/gif', 'extension' => 'gif', 'size' => 10, 'disk' => 'public'])->id;
        $source->items = [
            FakeCatalogSource::item('LARGO', ['imageMediaId' => $image, 'title' => str_repeat('ñ', 250), 'description' => '']),
            FakeCatalogSource::item('CHICA', ['imageMediaId' => $small]),
            FakeCatalogSource::item('SIN-PRECIO', ['imageMediaId' => $image, 'price' => null]),
            FakeCatalogSource::item('SIN-IMAGEN', ['imageMediaId' => null]),
            FakeCatalogSource::item('GIF', ['imageMediaId' => $gif]),
            FakeCatalogSource::item('p9', ['imageMediaId' => $image, 'groupId' => 'p9']),
        ];
        $token = $this->activate();

        $items = $this->items($this->feed($token)->assertOk()->streamedContent());

        $this->assertSame(['LARGO', 'CHICA'], array_keys($items));
        $this->assertSame(200, mb_strlen($items['LARGO']['title']));
        $this->assertSame($items['LARGO']['title'], $items['LARGO']['description']);

        $preview = app(ConnectorOperations::class)->preview('meta');
        $codes = [];
        foreach ($preview->warnings as $warning) {
            $codes[$warning->itemId][] = $warning->code;
        }
        $this->assertSame([FeedWarning::TITLE_TRUNCATED, FeedWarning::DESCRIPTION_FROM_TITLE], $codes['LARGO']);
        $this->assertSame([FeedWarning::IMAGE_TOO_SMALL], $codes['CHICA']);
        $this->assertSame([FeedWarning::MISSING_PRICE], $codes['SIN-PRECIO']);
        $this->assertSame([FeedWarning::MISSING_IMAGE], $codes['SIN-IMAGEN']);
        $this->assertSame([FeedWarning::IMAGE_NOT_CONVERTIBLE], $codes['GIF']);
        $this->assertSame([FeedWarning::NO_VARIANTS], $codes['p9']);
        $this->assertSame(200, $preview->notices()[0]->context['limit']);

        $record = Connector::query()->where('key', 'meta')->first();
        $this->assertSame(2, $record->last_item_count);
        $this->assertSame(4, $record->last_skipped_count);
    }

    public function test_write_accepts_any_iterable_and_stream_for_the_panel_preview(): void
    {
        $image = $this->jpeg('portada.jpg');
        $connector = app(ConnectorRegistry::class)->feed('meta');
        $stream = fopen('php://temp', 'w+b');

        $connector->write([FakeCatalogSource::item('X', ['imageMediaId' => $image])], [], $stream);
        rewind($stream);
        $body = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame(['X'], array_keys($this->items($body)));
        $this->assertStringStartsWith('<?xml', $body);
    }

    public function test_unknown_disabled_wrong_rotated_and_shop_off_answer_404(): void
    {
        $this->fakeSource()->items = [FakeCatalogSource::item('A', ['imageMediaId' => $this->jpeg('a.jpg')])];
        $valid = str_repeat('a', 48);

        $this->feed($valid)->assertNotFound(); // never activated
        $this->get('/feeds/google_merchant/'.$valid.'.xml')->assertNotFound();

        $token = $this->activate();
        $this->feed($token)->assertOk();
        $this->feed(str_repeat('b', 48))->assertNotFound();
        $this->get('/feeds/google_merchant/'.$token.'.xml')->assertNotFound();

        $admin = User::factory()->create(['role' => 'admin']);
        $rotated = app(ConnectorOperations::class)->rotateToken($admin, 'meta');
        $this->feed($token)->assertNotFound();
        $this->feed($rotated)->assertOk();

        $this->useShop('off');
        $this->feed($rotated)->assertNotFound();
        $this->useShop('catalog');
        $this->feed($rotated)->assertOk();

        app(ConnectorOperations::class)->deactivate($admin, 'meta');
        $this->feed($rotated)->assertNotFound();
        $this->assertNull(Connector::query()->where('key', 'meta')->first()->last_meta_fetched_at);
    }

    public function test_feed_is_throttled(): void
    {
        $this->fakeSource();
        $token = $this->activate();

        for ($i = 0; $i < 30; $i++) {
            $this->feed($token)->assertOk();
        }

        $this->feed($token)->assertStatus(429);
    }

    public function test_matching_etag_gets_304_and_is_still_logged(): void
    {
        $this->fakeSource()->items = [FakeCatalogSource::item('A', ['imageMediaId' => $this->jpeg('a.jpg')])];
        $token = $this->activate();

        $first = $this->feed($token)->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertMatchesRegularExpression('#^"[0-9a-f]+"$#', $etag);
        $this->assertStringContainsString('max-age=900', $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', $first->headers->get('Cache-Control'));

        $this->travel(5)->minutes();
        $second = $this->feed($token, ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertSame('', $second->getContent());
        $this->assertSame($etag, $second->headers->get('ETag'));
        $this->assertTrue(Connector::query()->where('key', 'meta')->first()->last_fetched_at->isSameMinute(now()));

        $this->feed($token, ['If-None-Match' => '"otro"'])->assertOk();
    }

    public function test_feed_opens_no_session_and_sets_no_cookies(): void
    {
        $this->fakeSource()->items = [FakeCatalogSource::item('A', ['imageMediaId' => $this->jpeg('a.jpg')])];
        $token = $this->activate();

        $ok = $this->feed($token, ['User-Agent' => self::META_AGENT])->assertOk();
        $this->assertSame([], $ok->headers->getCookies());
        $this->assertFalse($ok->headers->has('Set-Cookie'));
        $this->assertSame('max-age=900, public', $ok->headers->get('Cache-Control'));

        $notModified = $this->feed($token, ['If-None-Match' => $ok->headers->get('ETag')])->assertStatus(304);
        $this->assertFalse($notModified->headers->has('Set-Cookie'));
        $this->assertSame('max-age=900, public', $notModified->headers->get('Cache-Control'));

        // A wrong token is still a plain 404, without a session either, and the throttle still counts.
        $missing = $this->feed(str_repeat('b', 48))->assertNotFound();
        $this->assertFalse($missing->headers->has('Set-Cookie'));
        $this->assertStringNotContainsString('public', (string) $missing->headers->get('Cache-Control'));
        $this->assertContains('throttle:feeds', app('router')->getRoutes()->getByName('connectors.feed')->gatherMiddleware());
    }

    public function test_meta_user_agent_records_the_meta_fetch_and_others_only_the_last_fetch(): void
    {
        $this->fakeSource()->items = [FakeCatalogSource::item('A', ['imageMediaId' => $this->jpeg('a.jpg')])];
        $token = $this->activate();

        $this->feed($token, ['User-Agent' => 'curl/8.5.0'])->assertOk();
        $record = Connector::query()->where('key', 'meta')->first();
        $this->assertNotNull($record->last_fetched_at);
        $this->assertSame('curl/8.5.0', $record->last_fetch_agent);
        $this->assertNull($record->last_meta_fetched_at);

        $this->feed($token, ['User-Agent' => self::META_AGENT])->assertOk();
        $record->refresh();
        $this->assertNotNull($record->last_meta_fetched_at);
        $this->assertSame(self::META_AGENT, $record->last_fetch_agent);
    }

    public function test_generated_file_is_reused_and_rebuilt_after_a_product_is_saved(): void
    {
        $product = $this->product(['title' => 'Mate', 'slug' => 'mate', 'sku' => 'MATE', 'price_amount' => 150000]);
        $product->attachMedia($this->jpeg('mate.jpg'), 'featured_image');
        $token = $this->activate();
        $path = app(FeedFile::class)->path('meta');

        $this->assertSame('Mate', $this->items($this->feed($token)->assertOk()->streamedContent())['MATE']['title']);
        $this->assertFileExists($path);
        $this->assertSame([], glob(dirname($path).'/*.tmp'));

        // Not stale (built after the last touchFeeds() and within the TTL): served without rebuilding.
        $built = time() - 30;
        Cache::forever(ConnectorOperations::FEEDS_TOUCHED_KEY, $built - 30);
        touch($path, $built);
        clearstatcache();
        $this->feed($token)->assertOk();
        clearstatcache();
        $this->assertSame($built, filemtime($path));

        $product->update(['title' => 'Mate de calabaza']);
        $this->assertSame('Mate de calabaza', $this->items($this->feed($token)->assertOk()->streamedContent())['MATE']['title']);

        // Older than the TTL: rebuilt even without changes.
        $this->assertTrue(app(ConnectorOperations::class)->feedIsStale(time() - ConnectorOperations::FEED_TTL_SECONDS));
    }

    public function test_generated_file_is_rebuilt_when_the_site_origin_changes(): void
    {
        config(['app.url' => 'http://tienda-vieja.test']);
        $this->fakeSource()->items = [FakeCatalogSource::item('A', ['imageMediaId' => $this->jpeg('a.jpg')])];
        $token = $this->activate();
        $path = app(FeedFile::class)->path('meta');

        $old = $this->items($this->feed($token)->assertOk()->streamedContent())['A'];
        $this->assertStringStartsWith('http://tienda-vieja.test/', $old['link']);
        $this->assertStringStartsWith('http://tienda-vieja.test/', $old['image_link']);

        // Fresh by time (built after the last touchFeeds() and within the TTL): only the new domain makes it stale.
        $built = time() - 30;
        Cache::forever(ConnectorOperations::FEEDS_TOUCHED_KEY, $built - 30);
        touch($path, $built);
        clearstatcache();
        $this->feed($token)->assertOk();
        clearstatcache();
        $this->assertSame($built, filemtime($path));

        config(['app.url' => 'https://tienda-nueva.test/']);
        $new = $this->items($this->feed($token)->assertOk()->streamedContent())['A'];
        $this->assertStringStartsWith('https://tienda-nueva.test/', $new['link']);
        $this->assertStringStartsWith('https://tienda-nueva.test/', $new['image_link']);
        $this->assertStringNotContainsString('tienda-vieja.test', (string) file_get_contents($path));
        $this->assertSame([], glob(dirname($path).'/*.tmp'));
    }

    public function test_end_to_end_with_the_shop_source_converts_webp_to_jpeg(): void
    {
        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('GD WebP support is not available.');
        }
        $product = $this->product([
            'title' => 'Termo',
            'slug' => 'termo',
            'sku' => 'TER',
            'price_amount' => 200000,
            'compare_at_price_amount' => 250000,
            'description' => '<p>Acero <strong>inoxidable</strong> &amp; tapa. <a href="https://example.com">Ver</a></p>',
        ]);
        $product->attachMedia($this->webp('termo.webp', 800, 800), 'featured_image');
        $variants = $this->product(['title' => 'Remera', 'slug' => 'remera', 'sku' => 'REM', 'has_variants' => true]);
        $variants->attachMedia($this->webp('remera.webp', 600, 600), 'featured_image');
        $red = $variants->variants()->create(['sku' => 'REM-R', 'name' => 'Roja', 'attributes_json' => ['color' => 'Rojo'], 'price_amount' => 90000, 'stock' => 1, 'order' => 0]);
        $this->product(['title' => 'Sin precio', 'slug' => 'sin-precio', 'sku' => 'NADA'])->attachMedia($this->webp('nada.webp', 600, 600), 'featured_image');
        $token = $this->activate();

        $items = $this->items($this->feed($token, ['User-Agent' => self::META_AGENT])->assertOk()->streamedContent());

        $this->assertSame(['TER', 'REM-R'], array_keys($items));
        $termo = $items['TER'];
        $this->assertSame('250000 PYG', $termo['price']);
        $this->assertSame('200000 PYG', $termo['sale_price']);
        $this->assertSame('Acero inoxidable & tapa. Ver', $termo['description']);
        $this->assertSame(SiteUrl::origin().'/productos/termo', $termo['link']);
        $this->assertMatchesRegularExpression('#^https?://.+/feed/\d+-[0-9a-f]{16}\.jpg$#', $termo['image_link']);
        $jpeg = Storage::disk('public')->path('feed/'.basename($termo['image_link']));
        $this->assertSame('image/jpeg', getimagesize($jpeg)['mime']);

        $this->assertSame('REM', $items['REM-R']['item_group_id']);
        $this->assertSame(SiteUrl::origin().'/productos/remera?variante='.$red->id, $items['REM-R']['link']);
        $this->assertStringEndsWith('.jpg', $items['REM-R']['image_link']);

        $record = Connector::query()->where('key', 'meta')->first();
        $this->assertNotNull($record->last_meta_fetched_at);
        $this->assertSame(2, $record->last_item_count);
        $this->assertSame(1, $record->last_skipped_count);
        $this->assertInstanceOf(ShopProductSource::class, app(ConnectorRegistry::class)->source('products'));
    }

    /** Real Meta connector over an in-memory source. */
    private function fakeSource(): FakeCatalogSource
    {
        $source = new FakeCatalogSource;
        $this->app->instance(FakeCatalogSource::class, $source);
        config(['cms.catalog_sources' => ['products' => FakeCatalogSource::class]]);

        return $source;
    }

    private function activate(): string
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return app(ConnectorOperations::class)->activate($admin, 'meta')->plainToken();
    }

    /** @param array<string, string> $headers */
    private function feed(string $token, array $headers = []): TestResponse
    {
        return $this->withHeaders($headers)->get('/feeds/meta/'.$token.'.xml');
    }

    /** @return array<string, array<string, string>> g: fields per item id */
    private function items(string $body): array
    {
        $xml = simplexml_load_string($body);
        $this->assertInstanceOf(SimpleXMLElement::class, $xml);
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

    private function jpeg(string $filename, int $width = 800, int $height = 800): int
    {
        return Media::create([
            'filename' => $filename,
            'original_filename' => $filename,
            'path' => 'media/2026/11/'.$filename,
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size' => 1000,
            'width' => $width,
            'height' => $height,
            'disk' => 'public',
        ])->id;
    }

    private function webp(string $filename, int $width, int $height): int
    {
        $path = 'media/2026/11/'.$filename;
        $full = Storage::disk('public')->path($path);
        if (! is_dir(dirname($full))) {
            mkdir(dirname($full), 0755, true);
        }
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 40, 90, 200));
        imagewebp($image, $full, 80);

        return Media::create([
            'filename' => $filename,
            'original_filename' => $filename,
            'path' => $path,
            'mime_type' => 'image/webp',
            'extension' => 'webp',
            'size' => filesize($full),
            'width' => $width,
            'height' => $height,
            'disk' => 'public',
        ])->id;
    }

    private function product(array $attributes): Product
    {
        return Product::create($attributes + [
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);
    }
}
