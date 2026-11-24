<?php

namespace Tests\Feature;

use App\Connectors\CatalogItem;
use App\Connectors\Sources\ShopProductSource;
use App\Events\Shop\OrderPaid;
use App\Jobs\SendMetaPixelEvent;
use App\Models\Locale;
use App\Models\Order;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Forms\FormToken;
use App\Services\Marketing\MetaConversionsApi;
use App\Services\Marketing\MetaPixel;
use App\Services\Marketing\MetaPixelContent;
use App\Services\Marketing\MetaPixelOperations;
use App\Services\Marketing\MetaPixelUserData;
use App\Services\Shop\CartService;
use App\Support\SettingDefaults;
use Database\Seeders\ContactPageSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Support\CheckoutFixtures;
use Tests\TestCase;

/**
 * Meta Pixel events (docs/operations/meta-pixel.md): the browser fbq calls and their Conversions API twins with the
 * same event_id, where each one starts, who is not measured, the hashed customer data and the job that sends them.
 */
class MetaPixelEventsTest extends TestCase
{
    use RefreshDatabase;

    private const PIXEL = '123456789012345';

    private const TOKEN = 'EAABsbCS1iHgBOZCtestTokenValue0123456789abcdWXYZ';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::updateOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        RateLimiter::clear('forms');
        MetaPixel::resolveConsentUsing(null);
    }

    protected function tearDown(): void
    {
        MetaPixel::resolveConsentUsing(null);
        parent::tearDown();
    }

    private function activate(bool $withToken = true, string $testCode = ''): void
    {
        $operations = app(ConnectorOperations::class);
        $operations->updateSettings($this->admin, 'meta_pixel', ['pixel_id' => self::PIXEL, 'test_event_code' => $testCode], null);
        $operations->activate($this->admin, 'meta_pixel');
        if ($withToken) {
            app(MetaPixelOperations::class)->saveAccessToken($this->admin, self::TOKEN);
        }
    }

    /** @return list<array<string, mixed>> server events queued so far (after the response or after commit) */
    private function serverEvents(): array
    {
        return Bus::dispatchedAfterResponse(SendMetaPixelEvent::class)
            ->merge(Bus::dispatched(SendMetaPixelEvent::class))
            ->map(fn (SendMetaPixelEvent $job) => $job->event)->values()->all();
    }

    /** @return array<string, mixed> the single server event named $name */
    private function serverEvent(string $name): array
    {
        $events = array_values(array_filter($this->serverEvents(), fn (array $event) => $event['event_name'] === $name));
        $this->assertCount(1, $events, "One server {$name}.");

        return $events[0];
    }

    private function assertBrowserEvent(TestResponse $response, string $name, string $eventId): void
    {
        $response->assertSee("fbq('track', '".$name."',", false)->assertSee("{eventID: '".$eventId."'}", false);
    }

    private function home(): Page
    {
        return Page::create(['title' => 'Nosotros', 'slug' => 'nosotros', 'template_key' => 'default', 'content_json' => [], 'status' => 'published', 'published_at' => now()->subMinute(), 'locale' => 'es']);
    }

    public function test_nothing_loads_while_the_connector_is_off_and_the_analytics_pixel_stays_as_it_was(): void
    {
        $page = $this->home();
        $this->get($page->url())->assertOk()->assertDontSee('data-meta-pixel', false)->assertDontSee('fbevents.js', false);

        Setting::updateOrCreate(['key' => 'meta_pixel_id'], ['value' => '998877665544'] + SettingDefaults::for('meta_pixel_id'));
        Setting::clearCache();
        $legacy = $this->get($page->url())->assertOk()->getContent();
        $this->assertSame(1, substr_count($legacy, "fbq('init', '998877665544')"));
        $this->assertStringNotContainsString('data-meta-pixel', $legacy);

        // The connector takes over: one pixel, one PageView, with its event_id.
        Bus::fake([SendMetaPixelEvent::class]);
        $this->activate(withToken: false);
        $content = $this->get($page->url())->assertOk()->getContent();
        $this->assertSame(1, substr_count($content, "fbq('init'"));
        $this->assertStringContainsString("fbq('init', '".self::PIXEL."')", $content);
        $this->assertSame(1, substr_count($content, "fbq('track', 'PageView'"));
        $this->assertSame([], $this->serverEvents(), 'Without a token only the browser measures.');
    }

    public function test_page_view_goes_from_browser_and_server_with_the_same_event_id(): void
    {
        Bus::fake([SendMetaPixelEvent::class]);
        $this->activate();
        $page = $this->home();

        $response = $this->withUnencryptedCookies(['_fbp' => 'fb.1.1700000000000.1234567890'])
            ->withHeader('User-Agent', 'Mozilla/5.0 Visitante')
            ->get($page->url().'?fbclid=AbC_123-x')
            ->assertOk();

        $event = $this->serverEvent('PageView');
        $this->assertBrowserEvent($response, 'PageView', $event['event_id']);
        $response->assertSee('&amp;eid='.$event['event_id'], false);
        $this->assertSame('website', $event['action_source']);
        $this->assertSame(url($page->url()), $event['event_source_url'], 'Without the query string.');
        $this->assertSame('Mozilla/5.0 Visitante', $event['user_data']['client_user_agent']);
        $this->assertSame('127.0.0.1', $event['user_data']['client_ip_address']);
        $this->assertSame('fb.1.1700000000000.1234567890', $event['user_data']['fbp']);
        $this->assertMatchesRegularExpression('/^fb\.1\.\d{13}\.AbC_123-x$/', $event['user_data']['fbc']);
        $this->assertArrayNotHasKey('em', $event['user_data'], 'Contact details only exist for an order.');
        $this->assertStringNotContainsString(self::TOKEN, serialize($this->serverEvents()), 'The token never goes into the queue.');
    }

    public function test_panel_users_previews_and_a_refused_consent_are_not_measured(): void
    {
        Bus::fake([SendMetaPixelEvent::class]);
        $this->activate();
        $page = $this->home();

        $this->actingAs($this->admin)->get($page->url())->assertOk()->assertDontSee('data-meta-pixel', false);
        $this->assertSame([], $this->serverEvents());
        $this->assertSame('', trim(view('partials.meta-pixel', ['isEditorPreview' => true])->render()));

        MetaPixel::resolveConsentUsing(fn (Request $request) => $request->cookie('consent') === 'yes');
        $this->app['auth']->forgetGuards();
        $this->get($page->url())->assertOk()->assertDontSee('data-meta-pixel', false);
        $this->assertSame([], $this->serverEvents());
        $this->withCookie('consent', 'yes')->get($page->url())->assertOk()->assertSee('data-meta-pixel', false);
    }

    public function test_view_content_add_to_cart_and_initiate_checkout_name_the_feed_items(): void
    {
        Bus::fake([SendMetaPixelEvent::class]);
        $this->useCheckout();
        $this->activate();
        $mate = CheckoutFixtures::product(['title' => 'Mate', 'sku' => 'MATE-1', 'price_amount' => 150000]);
        $shirt = CheckoutFixtures::productWithVariants([['name' => 'Talle S', 'price_amount' => 90000, 'stock' => 5], ['name' => 'Talle L', 'sku' => 'REM-L', 'price_amount' => 95000, 'stock' => 5]]);
        $small = $shirt->variants[0];

        // The IDs are the feed's g:id / g:item_group_id.
        $feed = collect(app(ShopProductSource::class)->items())->keyBy(fn (CatalogItem $item) => $item->title);
        $this->assertSame($feed['Mate']->id, MetaPixelContent::forProduct($mate)['content_ids'][0]);
        $this->assertSame($feed[$shirt->title.' - Talle S']->id, MetaPixelContent::forProduct($shirt, $small)['content_ids'][0]);
        $this->assertSame($feed[$shirt->title.' - Talle L']->groupId, MetaPixelContent::forProduct($shirt)['content_ids'][0]);

        $page = $this->get($mate->url())->assertOk();
        $view = $this->serverEvent('ViewContent');
        $this->assertBrowserEvent($page, 'ViewContent', $view['event_id']);
        $this->assertSame(['content_ids' => ['MATE-1'], 'content_type' => 'product', 'content_name' => 'Mate', 'contents' => [['id' => 'MATE-1', 'quantity' => 1]], 'currency' => 'PYG', 'value' => 150000], $view['custom_data']);

        $this->get($shirt->url())->assertOk();
        $group = array_values(array_filter($this->serverEvents(), fn ($event) => $event['event_name'] === 'ViewContent' && $event['custom_data']['content_type'] === 'product_group'));
        $this->assertSame(['p'.$shirt->id], $group[0]['custom_data']['content_ids']);
        $this->get($shirt->url().'?variante='.$small->id)->assertOk();
        $this->assertContains(['v'.$small->id], array_map(fn ($event) => $event['custom_data']['content_ids'], array_filter($this->serverEvents(), fn ($event) => $event['event_name'] === 'ViewContent')));

        $added = $this->post(route('shop.cart.add'), ['product_id' => $shirt->id, 'variant_id' => $small->id, 'quantity' => 2]);
        $added->assertRedirect(CartService::route('cart.index'));
        // Decrypted: withCookie() encrypts it again for the next requests.
        $cartCookie = $added->getCookie(CartService::COOKIE)->getValue();
        $add = $this->serverEvent('AddToCart');
        $this->assertSame(['content_ids' => ['v'.$small->id], 'content_type' => 'product', 'content_name' => $shirt->title, 'contents' => [['id' => 'v'.$small->id, 'quantity' => 2]], 'currency' => 'PYG', 'value' => 180000], $add['custom_data']);
        $cart = $this->withCookie(CartService::COOKIE, $cartCookie)->get(CartService::route('cart.index'))->assertOk();
        $this->assertBrowserEvent($cart, 'AddToCart', $add['event_id']);
        $this->withCookie(CartService::COOKIE, $cartCookie)->get(CartService::route('cart.index'))->assertOk()->assertDontSee("fbq('track', 'AddToCart'", false);

        $checkout = $this->withCookie(CartService::COOKIE, $cartCookie)->get(route('shop.checkout.show'))->assertOk();
        $initiate = $this->serverEvent('InitiateCheckout');
        $this->assertBrowserEvent($checkout, 'InitiateCheckout', $initiate['event_id']);
        $this->assertSame(['v'.$small->id], $initiate['custom_data']['content_ids']);
        $this->assertSame([2, 180000, 'PYG'], [$initiate['custom_data']['num_items'], $initiate['custom_data']['value'], $initiate['custom_data']['currency']]);
    }

    public function test_purchase_is_sent_by_the_server_when_paid_and_shown_once_on_the_order_page(): void
    {
        Bus::fake([SendMetaPixelEvent::class]);
        $this->useCheckout();
        $this->activate();
        $order = CheckoutFixtures::order([[CheckoutFixtures::product(['title' => 'Termo', 'sku' => 'TERMO', 'price_amount' => 200000]), 2]], ['zone' => CheckoutFixtures::shippingZone('Centro', 30000)]);
        $order->forceFill(['ip' => '181.120.1.2', 'user_agent' => 'Mozilla/5.0 Cliente', 'customer_name' => 'Ana María Pérez', 'customer_email' => ' Ana@Example.com '])->save();
        app(MetaPixel::class)->rememberOrderContext($order, Request::create('/productos/checkout', 'POST', [], ['_fbp' => 'fb.1.1700000000000.42']));

        $token = $order->plainAccessToken();
        $this->get(route('shop.order.show', $token))->assertOk()->assertDontSee("fbq('track', 'Purchase'", false);

        $order->forceFill(['status' => Order::STATUS_PAID, 'paid_at' => now()])->save();
        event(new OrderPaid($order, $order->payments->first()));
        $purchase = $this->serverEvent('Purchase');
        $this->assertSame('purchase-'.$order->number, $purchase['event_id']);
        $this->assertSame(['content_ids' => ['TERMO'], 'content_type' => 'product', 'contents' => [['id' => 'TERMO', 'quantity' => 2]], 'num_items' => 2, 'currency' => 'PYG', 'value' => 430000, 'order_id' => $order->number], $purchase['custom_data']);
        $user = $purchase['user_data'];
        $this->assertSame(hash('sha256', 'ana@example.com'), $user['em']);
        $this->assertSame(hash('sha256', '595981123456'), $user['ph']);
        $this->assertSame([hash('sha256', 'ana'), hash('sha256', 'maríapérez'), hash('sha256', 'asunción'), hash('sha256', 'py')], [$user['fn'], $user['ln'], $user['ct'], $user['country']]);
        $this->assertSame(['181.120.1.2', 'Mozilla/5.0 Cliente', 'fb.1.1700000000000.42'], [$user['client_ip_address'], $user['client_user_agent'], $user['fbp']]);
        $this->assertStringNotContainsString($token, json_encode($purchase), 'The order URL token never reaches Meta.');
        $this->assertSame(CartService::route('checkout.show'), $purchase['event_source_url']);

        $page = $this->get(route('shop.order.show', $token))->assertOk();
        $this->assertBrowserEvent($page, 'Purchase', 'purchase-'.$order->number);
        $this->get(route('shop.order.show', $token))->assertOk()->assertDontSee("fbq('track', 'Purchase'", false);
        $pageViews = array_filter($this->serverEvents(), fn ($event) => $event['event_name'] === 'PageView');
        $this->assertNotEmpty($pageViews);
        foreach ($pageViews as $event) {
            $this->assertSame(CartService::route('checkout.show'), $event['event_source_url'], 'The order page URL is private.');
        }

        // An order placed by a signed-in panel user is not measured.
        $other = CheckoutFixtures::order();
        $request = Request::create('/productos/checkout', 'POST');
        $request->setUserResolver(fn () => $this->admin);
        app(MetaPixel::class)->rememberOrderContext($other, $request);
        $other->forceFill(['status' => Order::STATUS_PAID, 'paid_at' => now()])->save();
        event(new OrderPaid($other, $other->payments->first()));
        $this->assertCount(1, array_filter($this->serverEvents(), fn ($event) => $event['event_name'] === 'Purchase'));
    }

    public function test_lead_follows_a_real_contact_form_submission(): void
    {
        Bus::fake([SendMetaPixelEvent::class]);
        $this->seed([SettingsSeeder::class, ContactPageSeeder::class]);
        $this->activate();
        $contact = Page::where('slug', 'contacto')->firstOrFail();
        $payload = fn (array $overrides = []) => $overrides + [
            'page_id' => $contact->id, 'name' => 'Ana Pérez', 'email' => 'ana@example.com', 'phone' => '0981 123 456',
            'subject' => 'Consulta', 'message' => 'Hola, quisiera más información sobre sus servicios.', 'website' => '',
            '_form_token' => $this->formToken($contact),
        ];

        $this->post(route('forms.contact.store'), $payload(['website' => 'http://spam.example']))->assertRedirect();
        $this->assertSame([], array_filter($this->serverEvents(), fn ($event) => $event['event_name'] === 'Lead'), 'A honeypot hit is not a lead.');

        $response = $this->followingRedirects()->post(route('forms.contact.store'), $payload())->assertOk();
        $lead = $this->serverEvent('Lead');
        $this->assertBrowserEvent($response, 'Lead', $lead['event_id']);
        $this->assertArrayNotHasKey('custom_data', $lead);
        $this->assertArrayNotHasKey('em', $lead['user_data']);
    }

    public function test_the_job_sends_to_the_configured_graph_version_and_logs_failures_without_secrets(): void
    {
        $this->activate(testCode: 'TEST999');
        $event = app(MetaPixel::class)->serverEvent('Lead', 'evt-1', [], ['client_user_agent' => 'UA', 'client_ip_address' => '1.2.3.4'], 'https://example.com/contacto');
        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['events_received' => 1, 'fbtrace_id' => 'A'])
            ->push(['error' => ['message' => 'Invalid parameter '.self::TOKEN, 'code' => 100, 'fbtrace_id' => 'B']], 400)
            ->push([], 500)]);
        Log::spy();

        (new SendMetaPixelEvent($event))->handle(app(MetaPixel::class), app(MetaConversionsApi::class));
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://graph.facebook.com/v27.0/'.self::PIXEL.'/events'
            && $request['data'] === [$event] && $request['access_token'] === self::TOKEN && $request['test_event_code'] === 'TEST999');
        Log::shouldNotHaveReceived('warning');

        (new SendMetaPixelEvent($event))->handle(app(MetaPixel::class), app(MetaConversionsApi::class));
        (new SendMetaPixelEvent($event))->handle(app(MetaPixel::class), app(MetaConversionsApi::class));
        Log::shouldHaveReceived('warning')->twice()->withArgs(function (string $message, array $context) {
            $this->assertStringNotContainsString(self::TOKEN, json_encode($context));
            $this->assertSame(['event', 'status', 'message', 'fbtrace_id'], array_keys($context));

            return $context['event'] === 'Lead';
        });

        // Turned off meanwhile: nothing is sent.
        app(ConnectorOperations::class)->deactivate($this->admin, 'meta_pixel');
        Http::fake();
        (new SendMetaPixelEvent($event))->handle(app(MetaPixel::class), app(MetaConversionsApi::class));
        Http::assertNothingSent();
    }

    public function test_customer_data_is_normalized_as_meta_asks(): void
    {
        // Meta's own example (Customer Information Parameters).
        $this->assertSame('62a14e44f765419d10fea99367361a727c12365e2520f32218d505ed9aa0f62f', MetaPixelUserData::hash(mb_strtolower(trim(' John_Smith@gmail.com '))));
        $this->assertSame('595981123456', MetaPixelUserData::phone('0981 123-456'));
        $this->assertSame('595981123456', MetaPixelUserData::phone('+595 (981) 123 456'));
        $this->assertSame('595981123456', MetaPixelUserData::phone('00595981123456'));
        $this->assertSame('595981123456', MetaPixelUserData::phone('981123456'));
        $this->assertSame('16505551212', MetaPixelUserData::phone('+1 (650) 555-1212'));
        $this->assertSame('', MetaPixelUserData::phone('sin teléfono'));
        $this->assertSame(['valéry', 'sanlorenzo'], [MetaPixelUserData::letters(' Valéry! '), MetaPixelUserData::letters('San Lorenzo')]);
        $this->assertSame([], MetaPixelUserData::browserIds(Request::create('/', 'GET', ['fbclid' => '<script>'], ['_fbp' => 'nope', '_fbc' => 'fb.x'])));
    }

    public function test_a_failing_pixel_never_breaks_the_page_or_the_cart(): void
    {
        Bus::fake([SendMetaPixelEvent::class]);
        $this->useCheckout();
        $this->activate();
        MetaPixel::resolveConsentUsing(fn () => throw new \RuntimeException('banner down'));
        Log::spy();

        $product = CheckoutFixtures::product(['title' => 'Bombilla']);
        $this->get($product->url())->assertOk()->assertDontSee('data-meta-pixel', false);
        $this->post(route('shop.cart.add'), ['product_id' => $product->id])->assertRedirect(CartService::route('cart.index'))->assertSessionHasNoErrors();
        $this->assertSame([], $this->serverEvents());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $context === ['event' => 'consent', 'error' => \RuntimeException::class]);
    }

    private function formToken(Page $page): string
    {
        $this->travel(-10)->seconds();
        $token = app(FormToken::class)->issue($page->id, 'contact', 'es');
        $this->travelBack();

        return $token;
    }
}
