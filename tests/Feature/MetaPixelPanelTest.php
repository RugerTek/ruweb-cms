<?php

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorStatus;
use App\Connectors\MetaPixel\MetaPixelConnector;
use App\Models\Connector;
use App\Models\Setting;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Marketing\MetaPixel;
use App\Services\Marketing\MetaPixelOperations;
use App\Support\SettingDefaults;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Meta Pixel connector in the panel: settings, encrypted access token, activation and the test event. */
class MetaPixelPanelTest extends TestCase
{
    use RefreshDatabase;

    private const PIXEL = '123456789012345';

    private const TOKEN = 'EAABsbCS1iHgBOZCtestTokenValue0123456789abcdWXYZ';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function update(array $input): TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.connectors.meta-pixel.update', ['connector' => 'meta_pixel']), $input + ['pixel_id' => '', 'test_event_code' => '']);
    }

    public function test_it_is_registered_in_its_own_category_and_works_with_the_shop_off(): void
    {
        $this->useShop('off', 'off');
        $connector = app(ConnectorRegistry::class)->get('meta_pixel');
        $this->assertInstanceOf(MetaPixelConnector::class, $connector);
        $this->assertSame('marketing', $connector->category()->value);
        $this->assertSame(ConnectorStatus::NeedsAction, app(ConnectorRegistry::class)->status('meta_pixel'));

        $this->actingAs($this->admin)->get(route('admin.connectors.index'))
            ->assertOk()
            ->assertSee('data-connector="meta_pixel"', false)
            ->assertSee(__('meta_pixel.requirement_pixel_id'))
            ->assertSee(route('admin.connectors.show', ['connector' => 'meta_pixel']), false);

        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'meta_pixel']))
            ->assertOk()
            ->assertSee('data-meta-pixel-detail', false)
            ->assertSee(__('meta_pixel.token_missing'))
            ->assertSee(__('meta_pixel.events_without_shop'))
            ->assertDontSee('data-fetch-log', false);
    }

    public function test_the_help_names_meta_screens_as_events_manager_shows_them(): void
    {
        // Labels seen in Events Manager (Spanish UI) on 2026-11-24.
        $es = fn (string $key) => trans('meta_pixel.'.$key, [], 'es');
        $this->assertStringContainsString('«Conjuntos de datos»', $es('field_pixel_id_help'));
        $this->assertStringContainsString('«Identificador»', $es('field_pixel_id_help'));
        foreach (['«Configurar la integración directa»', '«Configurar sin Dataset Quality API»', '«Generar identificador de acceso»'] as $label) {
            $this->assertStringContainsString($label, $es('field_access_token_help'));
        }
        foreach (['«Sitio web»', '«Selecciona un canal»', 'test_event_code'] as $label) {
            $this->assertStringContainsString($label, $es('field_test_event_code_help'));
        }
        $this->assertStringContainsString('«Conectar ahora»', $es('field_access_token_no_meta_integration'));
        foreach (array_keys(require lang_path('es/meta_pixel.php')) as $key) {
            $this->assertStringNotContainsString('Events Manager', $es($key), "meta_pixel.{$key} uses Meta's Spanish name.");
        }

        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'meta_pixel']))
            ->assertOk()
            ->assertSee('data-meta-pixel-no-meta-integration', false)
            ->assertSee('>'.e(__('meta_pixel.link_events_manager')).'</a>', false);
    }

    public function test_the_admin_saves_the_pixel_and_an_encrypted_token_that_is_never_shown(): void
    {
        $this->update(['pixel_id' => self::PIXEL, 'access_token' => self::TOKEN, 'test_event_code' => 'TEST12345'])
            ->assertRedirect(route('admin.connectors.show', ['connector' => 'meta_pixel']))
            ->assertSessionHas('success', __('meta_pixel.saved'));

        $row = DB::table('connectors')->where('key', 'meta_pixel')->first();
        $this->assertSame(0, (int) $row->enabled, 'Saving does not activate.');
        $this->assertStringNotContainsString(self::TOKEN, (string) $row->secrets, 'The token is stored encrypted.');
        $this->assertSame(config('app.url'), $row->bound_origin);
        $this->assertSame(['pixel_id' => self::PIXEL, 'test_event_code' => 'TEST12345'], json_decode($row->settings_json, true));
        $this->assertSame(self::TOKEN, app(MetaPixel::class)->accessToken());
        $this->assertArrayNotHasKey('secrets', Connector::where('key', 'meta_pixel')->firstOrFail()->toArray());

        $page = $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'meta_pixel']))
            ->assertOk()
            ->assertSee(__('meta_pixel.token_saved', ['suffix' => 'WXYZ']))
            ->assertSee('value="'.self::PIXEL.'"', false);
        $this->assertStringNotContainsString(self::TOKEN, $page->getContent());
        $this->assertSame(ConnectorStatus::Available, app(ConnectorRegistry::class)->status('meta_pixel'));

        // An empty token field keeps the saved one; the checkbox removes it.
        $this->update(['pixel_id' => self::PIXEL])->assertSessionHasNoErrors();
        $this->assertSame(self::TOKEN, app(MetaPixel::class)->accessToken());
        $this->update(['pixel_id' => self::PIXEL, 'forget_access_token' => '1'])->assertSessionHasNoErrors();
        $this->assertNull(app(MetaPixel::class)->accessToken());
        $this->assertNull(DB::table('connectors')->where('key', 'meta_pixel')->value('secrets'));
    }

    public function test_invalid_input_saves_nothing(): void
    {
        $this->update(['pixel_id' => 'fbq("init")'])->assertSessionHasErrors('pixel_id');
        $this->update(['pixel_id' => self::PIXEL, 'access_token' => '<script>alert(1)</script>'])->assertSessionHasErrors('access_token');
        $this->update(['pixel_id' => self::PIXEL, 'test_event_code' => 'two words'])->assertSessionHasErrors('test_event_code');
        $this->assertSame(0, Connector::count(), 'A failed save rolls the settings back too.');

        $this->actingAs($this->admin)->put(route('admin.connectors.meta-pixel.update', ['connector' => 'meta']), ['pixel_id' => self::PIXEL])->assertNotFound();
        $this->actingAs($this->admin)->post(route('admin.connectors.meta-pixel.test', ['connector' => 'bancard']))->assertNotFound();
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->put(route('admin.connectors.meta-pixel.update', ['connector' => 'meta_pixel']), ['pixel_id' => self::PIXEL]);
        $this->assertSame(0, Connector::count());
    }

    public function test_activation_needs_the_pixel_id_and_the_card_says_how_it_measures(): void
    {
        $operations = app(ConnectorOperations::class);
        try {
            $operations->activate($this->admin, 'meta_pixel');
            $this->fail('Activating without a pixel ID must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(__('meta_pixel.requirement_pixel_id'), $exception->errors()['connector'][0]);
        }

        $this->update(['pixel_id' => self::PIXEL]);
        $this->actingAs($this->admin)->post(route('admin.connectors.activate', ['connector' => 'meta_pixel']))->assertRedirect();
        $this->assertSame(ConnectorStatus::Connected, app(ConnectorRegistry::class)->status('meta_pixel'));
        $this->actingAs($this->admin)->get(route('admin.connectors.index'))->assertSee('data-meta-pixel-status="browser"', false);

        $this->update(['pixel_id' => self::PIXEL, 'access_token' => self::TOKEN]);
        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'meta_pixel']))
            ->assertSee('data-meta-pixel-status="browser_and_server"', false)
            ->assertSee(route('admin.connectors.deactivate', ['connector' => 'meta_pixel']), false);
    }

    public function test_the_pixel_id_of_admin_analytics_is_the_default(): void
    {
        Setting::updateOrCreate(['key' => 'meta_pixel_id'], ['value' => '998877665544'] + SettingDefaults::for('meta_pixel_id'));
        Setting::clearCache();

        $this->assertSame('998877665544', app(ConnectorRegistry::class)->settings('meta_pixel')['pixel_id']);
        $this->assertSame(ConnectorStatus::Available, app(ConnectorRegistry::class)->status('meta_pixel'));
    }

    public function test_a_token_saved_under_another_origin_must_be_entered_again(): void
    {
        $this->update(['pixel_id' => self::PIXEL, 'access_token' => self::TOKEN]);
        config(['app.url' => 'https://otro-dominio.example']);

        $this->assertNull(app(MetaPixel::class)->accessToken());
        $this->assertTrue(Connector::where('key', 'meta_pixel')->firstOrFail()->secretsUnusable());
        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'meta_pixel']))
            ->assertSee(__('meta_pixel.token_unusable'))
            ->assertSee('data-token-status="unusable"', false);

        DB::table('connectors')->update(['bound_origin' => 'https://otro-dominio.example', 'secrets' => 'eyJpdiI6ImJyb2tlbiJ9']);
        $this->assertNull(app(MetaPixel::class)->accessToken(), 'A secret encrypted under another APP_KEY is unusable, not an error.');
    }

    public function test_the_test_event_reports_what_meta_answered_without_the_token(): void
    {
        $this->actingAs($this->admin)->post(route('admin.connectors.meta-pixel.test', ['connector' => 'meta_pixel']))
            ->assertSessionHasErrors(['test_event' => __('meta_pixel.test_needs_pixel_id')]);
        $this->update(['pixel_id' => self::PIXEL, 'access_token' => self::TOKEN]);
        $this->actingAs($this->admin)->post(route('admin.connectors.meta-pixel.test', ['connector' => 'meta_pixel']))
            ->assertSessionHasErrors(['test_event' => __('meta_pixel.test_needs_code')]);
        $this->update(['pixel_id' => self::PIXEL, 'test_event_code' => 'TEST12345']);

        Http::fake(['graph.facebook.com/*' => Http::sequence()
            ->push(['events_received' => 1, 'messages' => [], 'fbtrace_id' => 'AbC'])
            ->push(['error' => ['message' => 'Invalid OAuth access token: '.self::TOKEN, 'type' => 'OAuthException', 'code' => 190, 'fbtrace_id' => 'XyZ']], 400)]);

        $this->actingAs($this->admin)->withHeader('User-Agent', 'Mozilla/5.0 Test')->post(route('admin.connectors.meta-pixel.test', ['connector' => 'meta_pixel']))
            ->assertSessionHas('success', __('meta_pixel.test_sent'));
        Http::assertSent(function (HttpRequest $request) {
            $event = $request['data'][0];

            return $request->url() === 'https://graph.facebook.com/v27.0/'.self::PIXEL.'/events'
                && ! str_contains($request->url(), self::TOKEN)
                && $request['access_token'] === self::TOKEN
                && $request['test_event_code'] === 'TEST12345'
                && $event['event_name'] === 'PageView'
                && $event['action_source'] === 'website'
                && str_starts_with($event['event_id'], 'test-')
                && $event['user_data']['client_user_agent'] === 'Mozilla/5.0 Test'
                && $event['event_source_url'] === config('app.url').'/';
        });

        $failed = $this->actingAs($this->admin)->post(route('admin.connectors.meta-pixel.test', ['connector' => 'meta_pixel']))->assertSessionHasErrors('test_event');
        $message = session('errors')->first('test_event');
        $this->assertStringContainsString('Invalid OAuth access token', $message);
        $this->assertStringNotContainsString(self::TOKEN, $message);
        $this->assertStringNotContainsString(self::TOKEN, (string) $failed->getContent());
    }

    public function test_operations_refuse_editors(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $this->expectException(AuthorizationException::class);
        app(MetaPixelOperations::class)->saveAccessToken($editor, self::TOKEN);
    }
}
