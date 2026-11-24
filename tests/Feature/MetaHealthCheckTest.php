<?php

namespace Tests\Feature;

use App\Connectors\Meta\Connect\MetaConnection;
use App\Connectors\Meta\Connect\MetaConnectionStore;
use App\Console\Commands\MetaHealthCheckCommand;
use App\Events\Connectors\MetaConnectionLost;
use App\Mail\Connectors\MetaNotResponding;
use App\Mail\Connectors\MetaReconnectNeeded;
use App\Models\Locale;
use App\Models\Setting;
use App\Models\User;
use App\Services\Connectors\Meta\MetaCatalogSync;
use App\Services\Connectors\Meta\MetaSyncStatus;
use App\Services\Connectors\Meta\MetaUnreachableStreak;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeMetaGraph;
use Tests\TestCase;

/**
 * Meta v2 track 3c: the daily health check (meta:health-check), the one «Volver a conectar» email when the
 * connection goes to needs_reconnect and the «Meta no responde» warning (connection kept) after days without Meta, against FakeMetaGraph (Graph v27 answers of the spike). No request reaches Meta.
 */
class MetaHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private FakeMetaGraph $fake;

    /** While true, every Graph call answers a transient 503 (Meta down). */
    private bool $metaDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://mitienda.com.py', 'cms.shop.currency' => 'PYG']);
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Setting::updateOrCreate(['key' => 'site_name'], ['value' => 'Mates del Sur']);
        Setting::updateOrCreate(['key' => 'mail_enabled'], ['value' => '1', 'type' => 'boolean', 'group' => 'email']);
        Setting::clearCache();
        $this->useShop('catalog');
        $this->admin = User::factory()->create(['role' => 'admin', 'email' => 'duena@mitienda.com.py']);
        User::factory()->create(['role' => 'editor', 'email' => 'editor@mitienda.com.py']);
        Mail::fake();

        $this->fake = new FakeMetaGraph;
        Http::preventStrayRequests();
        Http::fake([rtrim((string) config('cms.meta.graph_url'), '/').'/*' => fn (Request $request) => $this->metaDown
            ? Http::response(['error' => ['message' => 'An unexpected error has occurred. Please retry your request later.', 'type' => 'OAuthException', 'code' => 2, 'is_transient' => true]], 503)
            : $this->fake->handle($request)]);
    }

    private function store(): MetaConnectionStore
    {
        return app(MetaConnectionStore::class);
    }

    /** Connected through the broker (2b) with the spike's catalog and feed (2c). */
    private function connect(?string $feedId = FakeMetaGraph::FEED_ID): MetaConnection
    {
        $connection = MetaConnection::connected(FakeMetaGraph::BUSINESS_ID, 'v27.0', ['catalog_management'], $this->admin->id, now())
            ->withCatalog(FakeMetaGraph::CATALOG_ID, 'Ruweb spike v2 commerce')
            ->withProductFeed($feedId);

        return $this->store()->save($connection, FakeMetaGraph::TOKEN);
    }

    private function check(): void
    {
        $this->artisan('meta:health-check')->assertSuccessful();
    }

    public function test_revoked_app_needs_reconnect_and_mails_the_administrators_once(): void
    {
        $this->connect();
        $this->fake->removeApp();

        $this->check();

        $connection = $this->store()->get();
        $this->assertTrue($connection->needsReconnect());
        $this->assertSame(__('meta_sync.error_revoked', [], 'es'), $connection->lastError);
        $this->assertNull($this->store()->accessToken());
        $this->assertSame(FakeMetaGraph::CATALOG_ID, $connection->catalogId);
        Mail::assertQueuedCount(1);
        Mail::assertQueued(MetaReconnectNeeded::class, fn (MetaReconnectNeeded $mail): bool => $mail->hasTo('duena@mitienda.com.py')
            && $mail->reason === __('meta_sync.error_revoked', [], 'es')
            && $mail->catalogName === 'Ruweb spike v2 commerce');
    }

    public function test_two_runs_and_repeated_losses_send_a_single_mail(): void
    {
        $this->connect();
        $this->fake->removeApp();

        $this->check();
        $calls = count($this->fake->calls());
        $this->check();
        $this->store()->markNeedsReconnect('Otra vez');

        $this->assertCount($calls, $this->fake->calls(), 'A pending reconnection is not checked again.');
        Mail::assertQueuedCount(1);
    }

    public function test_the_event_twice_for_the_same_connection_mails_once(): void
    {
        $connection = $this->connect();

        event(new MetaConnectionLost($connection, 'Meta cortó la conexión.'));
        event(new MetaConnectionLost($connection, 'Meta cortó la conexión.'));

        Mail::assertQueuedCount(1);
    }

    public function test_reconnecting_starts_over_and_the_next_loss_mails_again(): void
    {
        $this->connect();
        $this->fake->removeApp();
        $this->check();
        Mail::assertQueuedCount(1);

        $this->travel(2)->days();
        $this->fake = new FakeMetaGraph;
        $this->connect();
        $this->assertFalse($this->store()->get()->needsReconnect());
        $this->check();
        Mail::assertQueuedCount(1);
        $this->assertNull($this->store()->get()->lastError);

        $this->fake->removeApp();
        $this->check();
        $this->assertTrue($this->store()->get()->needsReconnect());
        Mail::assertQueuedCount(2);
    }

    public function test_unshared_catalog_needs_reconnect_with_the_reason(): void
    {
        $this->connect();
        $this->fake->unshareCatalog();

        $this->check();

        $connection = $this->store()->get();
        $this->assertTrue($connection->needsReconnect());
        $this->assertSame(__('meta_sync.error_catalog_unavailable', ['catalog' => 'Ruweb spike v2 commerce'], 'es'), $connection->lastError);
        Mail::assertQueued(MetaReconnectNeeded::class, fn (MetaReconnectNeeded $mail): bool => str_contains($mail->reason, 'Ruweb spike v2 commerce'));
    }

    public function test_a_loss_seen_by_the_feed_sync_also_mails(): void
    {
        $this->connect();
        $this->fake->removeApp();

        $this->assertSame(MetaSyncStatus::NEEDS_RECONNECT, app(MetaCatalogSync::class)->status()->state);

        $this->assertTrue($this->store()->get()->needsReconnect());
        Mail::assertQueuedCount(1);
    }

    public function test_healthy_connection_records_the_check(): void
    {
        $this->connect();
        $this->store()->recordHealth('Un error viejo');

        $this->check();

        $connection = $this->store()->get();
        $this->assertFalse($connection->needsReconnect());
        $this->assertNull($connection->lastError);
        $this->assertNotNull($connection->lastHealthAt);
        $paths = array_column($this->fake->calls(), 'path');
        $this->assertContains('me', $paths);
        $this->assertContains(FakeMetaGraph::CATALOG_ID, $paths);
        $this->assertContains(FakeMetaGraph::FEED_ID, $paths);
        Mail::assertNothingQueued();
    }

    public function test_missing_feed_is_flagged_by_the_sync_not_recreated(): void
    {
        $this->connect(feedId: '1666548451688052');

        $this->check();

        $connection = $this->store()->get();
        $this->assertFalse($connection->needsReconnect());
        $this->assertNull($connection->productFeedId);
        $this->assertSame(__('meta_sync.error_feed_missing', [], 'es'), $connection->lastError);
        $this->assertNotContains('POST', array_column($this->fake->calls(), 'method'));
        Mail::assertNothingQueued();
    }

    public function test_transient_failure_keeps_the_state_and_records_the_error(): void
    {
        $this->connect();
        $this->metaDown = true;

        $this->check();

        $connection = $this->store()->get();
        $this->assertFalse($connection->needsReconnect());
        $this->assertNotNull($this->store()->accessToken());
        $this->assertStringContainsString('mañana', (string) $connection->lastError);
        Mail::assertNothingQueued();
    }

    public function test_three_consecutive_days_without_meta_warn_once_but_keep_the_connection(): void
    {
        $this->connect();
        $this->metaDown = true;

        $this->check();
        $this->check();
        $this->travel(1)->days();
        $this->check();
        $this->assertSame(2, app(MetaUnreachableStreak::class)->days($this->store()->get()));
        $this->assertStringContainsString('mañana', (string) $this->store()->get()->lastError);
        Mail::assertNothingQueued();

        $this->travel(1)->days();
        $this->check();

        $connection = $this->store()->get();
        $this->assertFalse($connection->needsReconnect(), 'Meta not answering never drops the connection.');
        $this->assertSame(FakeMetaGraph::TOKEN, $this->store()->accessToken());
        $this->assertSame(__('meta_health.error_unreachable_days', ['days' => MetaHealthCheckCommand::UNREACHABLE_DAYS], 'es'), $connection->lastError);
        $this->assertStringContainsString('revisá la conexión del servidor', (string) $connection->lastError);
        Mail::assertQueuedCount(1);
        Mail::assertQueued(MetaNotResponding::class, fn (MetaNotResponding $mail): bool => $mail->hasTo('duena@mitienda.com.py')
            && $mail->days === 3 && $mail->catalogName === 'Ruweb spike v2 commerce');
        Mail::assertNotQueued(MetaReconnectNeeded::class);

        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'meta']))
            ->assertOk()
            ->assertSee('data-meta-unreachable="3"', false)
            ->assertSee(__('meta_health.error_unreachable_days', ['days' => 3], 'es'));

        $this->travel(1)->days();
        $this->check();
        $this->assertSame(__('meta_health.error_unreachable_days', ['days' => 4], 'es'), $this->store()->get()->lastError);
        Mail::assertQueuedCount(1);
    }

    public function test_meta_answering_again_clears_the_warning_by_itself_and_a_new_streak_mails_again(): void
    {
        $this->connect();
        $this->metaDown = true;
        foreach (range(1, 3) as $day) {
            $this->check();
            $this->travel(1)->days();
        }
        Mail::assertQueuedCount(1);

        $this->metaDown = false;
        $this->check();

        $connection = $this->store()->get();
        $this->assertFalse($connection->needsReconnect());
        $this->assertNull($connection->lastError);
        $this->assertSame(0, app(MetaUnreachableStreak::class)->days($connection));
        $this->actingAs($this->admin)->get(route('admin.connectors.show', ['connector' => 'meta']))
            ->assertOk()->assertDontSee('data-meta-unreachable', false);

        $this->metaDown = true;
        foreach (range(1, 3) as $day) {
            $this->travel(1)->days();
            $this->check();
        }
        Mail::assertQueuedCount(2);
        $this->assertFalse($this->store()->get()->needsReconnect());
    }

    public function test_not_responding_mail_text(): void
    {
        $html = (new MetaNotResponding(3, 'Mi catálogo'))->render();

        $this->assertStringContainsString('Mi catálogo', $html);
        $this->assertStringContainsString(__('meta_health.unreachable_mail_action', [], 'es'), $html);
        $this->assertStringContainsString(e(__('meta_health.unreachable_mail_action_help', [], 'es')), $html);
        $this->assertStringContainsString(route('admin.connectors.show', ['connector' => 'meta']), $html);
        $this->assertStringContainsString(route('admin.login'), $html);
        (new MetaNotResponding(3))->assertHasSubject(__('meta_health.unreachable_mail_subject', ['days' => 3, 'site' => 'Mates del Sur'], 'es'));
    }

    public function test_a_good_day_or_a_gap_resets_the_count(): void
    {
        $this->connect();
        $this->metaDown = true;
        $this->check();
        $this->travel(1)->days();
        $this->check();

        $this->metaDown = false;
        $this->travel(1)->days();
        $this->check();
        $this->metaDown = true;
        $this->travel(1)->days();
        $this->check();
        $this->travel(1)->days();
        $this->check();
        $this->assertFalse($this->store()->get()->needsReconnect());

        $this->travel(3)->days();
        $this->check();
        $this->assertFalse($this->store()->get()->needsReconnect(), 'A day without a run breaks the streak.');
        Mail::assertNothingQueued();
    }

    public function test_manual_mode_calls_nothing(): void
    {
        $this->check();

        Http::assertNothingSent();
        $this->assertTrue($this->store()->get()->isManual());
        Mail::assertNothingQueued();
    }

    public function test_mail_disabled_only_logs(): void
    {
        Setting::updateOrCreate(['key' => 'mail_enabled'], ['value' => '0']);
        Setting::clearCache();
        $this->connect();
        $this->fake->removeApp();

        $this->check();

        $this->assertTrue($this->store()->get()->needsReconnect());
        Mail::assertNothingQueued();
    }

    public function test_without_administrator_emails_the_contact_email_gets_it(): void
    {
        $this->admin->forceFill(['email' => 'no-es-un-mail'])->save();
        Setting::updateOrCreate(['key' => 'contact_email'], ['value' => 'hola@mitienda.com.py']);
        Setting::clearCache();
        $this->connect();
        $this->fake->removeApp();

        $this->check();

        Mail::assertQueued(MetaReconnectNeeded::class, fn (MetaReconnectNeeded $mail): bool => $mail->hasTo('hola@mitienda.com.py'));
        Mail::assertQueuedCount(1);
    }

    public function test_mail_text_has_the_reason_and_the_reconnect_button(): void
    {
        $html = (new MetaReconnectNeeded('Meta cortó la conexión.', 'Mi catálogo'))->render();

        $this->assertStringContainsString('Meta cortó la conexión.', $html);
        $this->assertStringContainsString('Mi catálogo', $html);
        $this->assertStringContainsString(__('meta_health.mail_action', [], 'es'), $html);
        $this->assertStringContainsString(route('admin.connectors.show', ['connector' => 'meta']), $html);
        $this->assertStringContainsString(route('admin.login'), $html);
        $this->assertStringNotContainsString(FakeMetaGraph::TOKEN, $html);
        (new MetaReconnectNeeded('x'))->assertHasSubject(__('meta_health.mail_subject', ['site' => 'Mates del Sur'], 'es'));
    }

    public function test_the_check_is_scheduled_daily_and_never_prints_the_token(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'meta:health-check'));
        $this->assertNotNull($event);
        $this->assertSame('0 0 * * *', $event->expression);

        $this->connect();
        $this->fake->removeApp();
        $this->artisan('meta:health-check')->doesntExpectOutputToContain(FakeMetaGraph::TOKEN)->assertSuccessful();
    }
}
