<?php

namespace Tests\Feature;

use App\Connectors\AmibaChat\AmibaChatConnector;
use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorStatus;
use App\Models\Connector;
use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Page;
use App\Models\User;
use App\Services\AmibaChat\AmibaChatOperations;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Shop\CartService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\Support\CheckoutFixtures;
use Tests\TestCase;

/**
 * Amiba chatbot connector, level 1 (docs/operations/connectors.md#amiba-chatbot-card): the widget code (alone or the
 * pasted snippet), the loader on public pages only while active, never in the panel or its previews, "hide on the
 * checkout", the "Test" button against Amiba's API (faked: no real call), MCP and permissions.
 */
class AmibaChatConnectorTest extends TestCase
{
    use RefreshDatabase;

    private const HANDLE = '0192f7c4-1a2b-7c3d-8e4f-0123456789ab';

    private const LOADER = 'https://widget.amiba.io/loader.js';

    private const CONFIG_URL = 'https://api.amiba.io/webchat/'.self::HANDLE.'/config';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::updateOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        RateLimiter::clear('forms');
        Http::preventStrayRequests();
    }

    private function save(array $input): TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.connectors.amiba-chat.update', ['connector' => 'amiba_chat']), $input + ['handle' => '']);
    }

    private function activate(bool $hideOnCheckout = false): void
    {
        $operations = app(ConnectorOperations::class);
        $operations->updateSettings($this->admin, 'amiba_chat', ['handle' => self::HANDLE, 'hide_on_checkout' => $hideOnCheckout], null);
        $operations->activate($this->admin, 'amiba_chat');
    }

    private function page(): Page
    {
        return Page::create(['title' => 'Nosotros', 'slug' => 'nosotros', 'template_key' => 'default', 'content_json' => [], 'status' => 'published', 'published_at' => now()->subMinute(), 'locale' => 'es']);
    }

    private function settings(): array
    {
        return app(ConnectorRegistry::class)->settings('amiba_chat');
    }

    private function loaderTag(): string
    {
        return '<script src="'.self::LOADER.'" data-amiba-widget="'.self::HANDLE.'" async></script>';
    }

    private function pressTest(): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('admin.connectors.amiba-chat.test', ['connector' => 'amiba_chat']));
    }

    public function test_it_is_registered_in_customer_service_and_works_with_the_shop_off(): void
    {
        $this->useShop('off', 'off');
        $connector = app(ConnectorRegistry::class)->get('amiba_chat');
        $this->assertInstanceOf(AmibaChatConnector::class, $connector);
        $this->assertSame('customer_service', $connector->category()->value);
        $this->assertSame('Atención al cliente', trans('connectors.category_customer_service', [], 'es'));
        $this->assertSame('Customer service', trans('connectors.category_customer_service', [], 'en'));
        $this->assertSame(ConnectorStatus::NeedsAction, app(ConnectorRegistry::class)->status('amiba_chat'));

        $this->actingAs($this->admin)->get(route('admin.connectors.index'))->assertOk()
            ->assertSeeInOrder(['data-connector-group="customer_service"', __('connectors.category_customer_service'), 'data-connector="amiba_chat"'], false)
            ->assertSee(__('amiba_chat.requirement_handle'))
            ->assertSee(route('admin.connectors.show', ['connector' => 'amiba_chat']), false);

        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'amiba_chat']))->assertOk()
            ->assertSee('data-amiba-chat-detail', false)
            ->assertSee('data-amiba-guide', false)
            ->assertSee('http://localhost:8000', false)
            ->assertSee('«Orígenes permitidos»', false)
            ->assertDontSee('data-fetch-log', false);

        // Activating without a widget code is refused.
        $this->expectException(ValidationException::class);
        app(ConnectorOperations::class)->activate($this->admin, 'amiba_chat');
    }

    public function test_the_handle_is_saved_alone_or_from_the_pasted_snippet(): void
    {
        $this->save(['handle' => '  '.self::HANDLE.' '])->assertRedirect(route('admin.connectors.show', ['connector' => 'amiba_chat']))->assertSessionHasNoErrors();
        $this->assertSame([self::HANDLE, false], [$this->settings()['handle'], $this->settings()['hide_on_checkout']]);

        // The floating widget snippet as Amiba's panel copies it (staging adds data-api: only the handle is kept).
        $snippet = '<script src="https://widget.amiba.io/loader.js" data-amiba-widget="'.strtoupper(self::HANDLE).'" data-api="https://staging.example" async></script>';
        $this->save(['handle' => $snippet, 'hide_on_checkout' => '1'])->assertSessionHasNoErrors();
        $this->assertSame([self::HANDLE, true], [$this->settings()['handle'], $this->settings()['hide_on_checkout']]);

        // The iframe embed and single quotes work too.
        $this->save(['handle' => '<iframe src="https://widget.amiba.io/w/'.self::HANDLE.'" style="border:0"></iframe>'])->assertSessionHasNoErrors();
        $this->assertSame(self::HANDLE, $this->settings()['handle']);
        $this->assertSame(self::HANDLE, AmibaChatConnector::extractHandle("<script data-amiba-widget='".self::HANDLE."' async></script>"));

        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'amiba_chat']))->assertOk()
            ->assertSee('data-amiba-snippet', false)
            ->assertSee(e($this->loaderTag()), false);
    }

    public function test_an_invalid_code_is_refused_with_a_clear_message_and_nothing_is_saved(): void
    {
        $this->save(['handle' => self::HANDLE]);
        foreach (['hola', '<script src="https://widget.amiba.io/loader.js" async></script>', '<script data-amiba-widget="x"><\/script>', self::HANDLE.'x', 'https://evil.example/loader.js'] as $bad) {
            $this->from(route('admin.connectors.show', ['connector' => 'amiba_chat']))->save(['handle' => $bad])
                ->assertRedirect(route('admin.connectors.show', ['connector' => 'amiba_chat']))
                ->assertSessionHasErrors(['handle' => __('amiba_chat.error_handle_format')]);
            $this->assertSame(self::HANDLE, $this->settings()['handle'], $bad);
        }
        // Emptying the field is allowed (the connector then needs action again).
        $this->save(['handle' => ''])->assertSessionHasNoErrors();
        $this->assertSame(ConnectorStatus::NeedsAction, app(ConnectorRegistry::class)->status('amiba_chat'));
    }

    public function test_the_loader_is_on_public_pages_only_while_active_also_for_panel_users(): void
    {
        $page = $this->page();
        $this->get($page->url())->assertOk()->assertDontSee('data-amiba-widget', false);

        // Saved but not active: nothing yet.
        app(ConnectorOperations::class)->updateSettings($this->admin, 'amiba_chat', ['handle' => self::HANDLE], null);
        $this->get($page->url())->assertOk()->assertDontSee('data-amiba-widget', false);

        app(ConnectorOperations::class)->activate($this->admin, 'amiba_chat');
        $html = $this->get($page->url())->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, $this->loaderTag()));
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, $this->loaderTag()));
        // A chat measures nothing: someone signed in to the panel sees it on the site too.
        $this->actingAs($this->admin)->get($page->url())->assertOk()->assertSee($this->loaderTag(), false);

        app(ConnectorOperations::class)->deactivate($this->admin, 'amiba_chat');
        $this->get($page->url())->assertOk()->assertDontSee('data-amiba-widget', false);
    }

    public function test_the_loader_never_shows_in_the_panel_or_its_previews(): void
    {
        $this->activate();
        $page = $this->page();

        $this->actingAs($this->admin)->get(route('admin.connectors.index'))->assertOk()->assertDontSee(self::LOADER, false);
        $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->assertDontSee(self::LOADER, false);
        $this->actingAs($this->admin)->get(route('admin.content.preview', ['type' => 'pages', 'id' => $page->id]))->assertOk()->assertDontSee(self::LOADER, false);
        $this->assertSame('', trim(view('partials.amiba-chat', ['isEditorPreview' => true])->render()));
        $this->assertStringNotContainsString(self::LOADER, view($page->previewView(), $page->previewData() + ['isEditorPreview' => true])->render());
    }

    public function test_hide_on_the_checkout_keeps_the_rest_of_the_shop(): void
    {
        $this->useCheckout();
        $mate = CheckoutFixtures::product(['title' => 'Mate', 'sku' => 'MATE-1', 'price_amount' => 150000]);
        $added = $this->post(route('shop.cart.add'), ['product_id' => $mate->id, 'quantity' => 1]);
        $cartCookie = $added->getCookie(CartService::COOKIE)->getValue();
        $checkout = fn () => $this->withCookie(CartService::COOKIE, $cartCookie)->get(route('shop.checkout.show'))->assertOk();

        // Visible by default, checkout included.
        $this->activate();
        $checkout()->assertSee($this->loaderTag(), false);

        $this->activate(hideOnCheckout: true);
        $checkout()->assertDontSee('data-amiba-widget', false);
        $this->withCookie(CartService::COOKIE, $cartCookie)->get(CartService::route('cart.index'))->assertOk()->assertSee($this->loaderTag(), false);
        $this->get($mate->url())->assertOk()->assertSee($this->loaderTag(), false);
        $this->actingAs($this->admin)->get(route('admin.connectors.index'))->assertOk()->assertSee('data-amiba-chat-status="not_on_checkout"', false);
    }

    public function test_the_test_button_explains_what_amiba_answered(): void
    {
        // The button is throttled (6 a minute); this test presses it more often.
        $this->withoutMiddleware(ThrottleRequests::class);

        // No code yet: nothing is asked.
        $this->pressTest()->assertSessionHasErrors(['test' => __('amiba_chat.test_needs_handle')]);
        Http::assertNothingSent();
        app(ConnectorOperations::class)->updateSettings($this->admin, 'amiba_chat', ['handle' => self::HANDLE], null);
        $result = fn () => session('amiba_chat_test');
        $answer = null;
        Http::fake(function (HttpRequest $request) use (&$answer) {
            return $request->url() === self::CONFIG_URL ? $answer() : Http::response(null, 500);
        });

        // Found and this site allowed (Amiba normalizes case and trailing slash).
        $answer = fn () => Http::response(['title' => 'Asistente', 'allowedOrigins' => ['https://otro.com', 'HTTP://LOCALHOST:8000/']]);
        $this->pressTest()->assertRedirect(route('admin.connectors.show', ['connector' => 'amiba_chat']));
        $this->assertSame(['ok', true], [$result()['outcome'], $result()['ok']]);
        $this->assertStringContainsString('«Asistente»', $result()['message']);
        Http::assertSent(fn (HttpRequest $request) => $request->method() === 'GET' && $request->url() === self::CONFIG_URL);

        // Found, empty list: any site may show it; the message suggests adding this one.
        $answer = fn () => Http::response(['title' => '', 'allowedOrigins' => []]);
        $this->pressTest();
        $this->assertSame('ok_any_site', $result()['outcome']);
        $this->assertStringContainsString('http://localhost:8000', $result()['message']);

        // Found, this site not in the list: say exactly what to add in Amiba.
        $answer = fn () => Http::response(['title' => 'Asistente', 'allowedOrigins' => ['https://www.example.com', 'http://localhost:9000']]);
        $this->pressTest();
        $this->assertSame(['origin_not_allowed', false, 'http://localhost:8000'], [$result()['outcome'], $result()['ok'], $result()['origin']]);
        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'amiba_chat']))->assertOk()
            ->assertSee('data-amiba-test-result="origin_not_allowed"', false)
            ->assertSee('<p class="mt-1 font-mono text-white" data-amiba-origin>http://localhost:8000</p>', false)
            ->assertSee(e(__('amiba_chat.test_add_this')), false);

        // The 403 Amiba answers when a chat starts from a site outside the list.
        $answer = fn () => Http::response(['error' => 'origin_not_allowed'], 403);
        $this->pressTest();
        $this->assertSame('origin_not_allowed', $result()['outcome']);

        // Unknown handle or Web Chat off.
        $answer = fn () => Http::response(null, 404);
        $this->pressTest();
        $this->assertSame(['not_found', false], [$result()['outcome'], $result()['ok']]);
        $this->assertSame(__('amiba_chat.test_not_found'), $result()['message']);

        // Timeout or no connection.
        $answer = fn () => throw new ConnectionException('cURL error 28: Operation timed out');
        $this->pressTest();
        $this->assertSame('unreachable', $result()['outcome']);

        // Anything else.
        $answer = fn () => Http::response(['error' => 'rate'], 429);
        $this->pressTest();
        $this->assertSame('unexpected', $result()['outcome']);
        $this->assertStringContainsString('429', $result()['message']);

        // The test never writes.
        $this->assertNull(app(ConnectorRegistry::class)->record('amiba_chat')->secrets);
        $this->assertFalse((bool) app(ConnectorRegistry::class)->record('amiba_chat')->enabled);
    }

    public function test_the_test_waits_for_amiba_with_a_timeout(): void
    {
        config(['cms.amiba_chat.timeout' => 3]);
        app(ConnectorOperations::class)->updateSettings($this->admin, 'amiba_chat', ['handle' => self::HANDLE], null);
        $options = null;
        Http::fake(function (HttpRequest $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response(['allowedOrigins' => []]);
        });
        app(AmibaChatOperations::class)->test($this->admin);
        $this->assertSame(3, $options['timeout'] ?? null);
    }

    public function test_mcp_shows_the_handle_and_can_change_it_and_activate(): void
    {
        $this->withToken(McpApiKey::issue($this->admin, 'Amiba', 'full', null));
        $rpc = fn (string $tool, array $args = []) => $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]);
        $data = function (TestResponse $response): array {
            $response->assertOk()->assertJsonMissingPath('error');
            $this->assertFalse($response->json('result.isError'), (string) $response->json('result.content.0.text'));

            return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
        };

        $before = $data($rpc('connectors_get', ['connector' => 'amiba_chat']))['data'];
        $this->assertSame(['needs_action', false, false, 'amiba_handle'], [$before['status'], $before['enabled'], $before['is_feed'], $before['requirements'][0]['key']]);
        $this->assertSame(['handle', 'hide_on_checkout'], $before['setting_keys']);
        $this->assertSame([null, false, self::LOADER, 'http://localhost:8000'], [$before['amiba_chat']['handle'], $before['amiba_chat']['on_public_pages'], $before['amiba_chat']['loader_url'], $before['amiba_chat']['site_origin']]);

        // A bad code is refused and nothing is saved.
        $rpc('connectors_update', ['connector' => 'amiba_chat', 'expected_revision' => $before['revision'], 'settings' => ['handle' => 'hola']])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertNull(app(ConnectorRegistry::class)->record('amiba_chat'));

        // The pasted snippet works here too, and so does activating in the same call.
        $updated = $data($rpc('connectors_update', ['connector' => 'amiba_chat', 'expected_revision' => $before['revision'], 'enabled' => true,
            'settings' => ['handle' => '<script src="'.self::LOADER.'" data-amiba-widget="'.self::HANDLE.'" async></script>']]))['data'];
        $this->assertSame(['connected', true, self::HANDLE, self::HANDLE, true], [$updated['status'], $updated['enabled'], $updated['settings']['handle'], $updated['amiba_chat']['handle'], $updated['amiba_chat']['on_public_pages']]);
        $this->get($this->page()->url())->assertSee($this->loaderTag(), false);

        $list = $data($rpc('connectors_list'))['data'];
        $row = collect($list)->firstWhere('key', 'amiba_chat');
        $this->assertSame([self::HANDLE, 'connected'], [$row['amiba_chat']['handle'], $row['status']]);

        $this->getJson('/mcp/connectors/amiba_chat')->assertOk()->assertJsonPath('data.amiba_chat.handle', self::HANDLE);
        $this->patchJson('/mcp/connectors/amiba_chat', ['expected_revision' => $updated['revision'], 'enabled' => false, 'settings' => ['hide_on_checkout' => true]])
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.amiba_chat.hide_on_checkout', true);

        // No feed: nothing to rotate or preview.
        $this->getJson('/mcp/connectors/amiba_chat/preview')->assertNotFound();
    }

    public function test_editors_cannot_configure_activate_or_test(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->get(route('admin.connectors.show', ['connector' => 'amiba_chat']))->assertForbidden();
        $this->actingAs($editor)->put(route('admin.connectors.amiba-chat.update', ['connector' => 'amiba_chat']), ['handle' => self::HANDLE])->assertForbidden();
        $this->actingAs($editor)->post(route('admin.connectors.amiba-chat.test', ['connector' => 'amiba_chat']))->assertForbidden();
        $this->actingAs($editor)->post(route('admin.connectors.activate', ['connector' => 'amiba_chat']))->assertForbidden();
        $this->assertNull(app(ConnectorRegistry::class)->record('amiba_chat'));

        $this->withToken(McpApiKey::issue($editor, 'Editor', 'full', null));
        $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'connectors_get', 'arguments' => ['connector' => 'amiba_chat']]])
            ->assertOk()->assertJsonPath('error.code', -32003);

        $this->expectException(AuthorizationException::class);
        app(AmibaChatOperations::class)->test($editor);
    }

    public function test_the_amiba_only_routes_answer_404_for_other_connectors(): void
    {
        $this->actingAs($this->admin)->put(route('admin.connectors.amiba-chat.update', ['connector' => 'meta_pixel']), ['handle' => self::HANDLE])->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.connectors.amiba-chat.test', ['connector' => 'meta_pixel']))->assertNotFound();
        $this->assertNull(Connector::query()->where('key', 'meta_pixel')->first());
    }
}
