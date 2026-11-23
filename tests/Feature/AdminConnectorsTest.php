<?php

namespace Tests\Feature;

use App\Connectors\ConnectorRegistry;
use App\Connectors\ConnectorStatus;
use App\Models\Connector;
use App\Models\Locale;
use App\Models\Product;
use App\Models\User;
use App\Services\Connectors\ConnectorOperations;
use App\Support\ShopModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FakeCatalogSource;
use Tests\Support\FakeFeedConnector;
use Tests\TestCase;

/** Admin > Connectors panel (PLAN.md §4.1–4.4, stage 2 track 2C), with the fake connector registered as `meta`. */
class AdminConnectorsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private FakeFeedConnector $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        $this->useShop('off');
        $this->fake = $this->useFakeConnectors();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
    }

    private function source(): FakeCatalogSource
    {
        return app(FakeCatalogSource::class);
    }

    private function operations(): ConnectorOperations
    {
        return app(ConnectorOperations::class);
    }

    /** Catalog on and the connector active; returns its row. */
    private function connected(): Connector
    {
        $this->useShop('catalog');

        return $this->operations()->activate($this->admin, 'meta');
    }

    public function test_admin_sees_connectors_with_the_shop_off_and_the_meta_card_offers_the_catalog(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.connectors.index'))->assertSee(__('connectors.title'));
        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee(__('connectors.meta_label'))
            ->assertSee('data-status="needs_action"', false)
            ->assertSee(__('connectors.status_needs_action'))
            ->assertSee(__('connectors.meta_needs_catalog'))
            ->assertSee(__('connectors.action_enable_catalog'))
            ->assertSee(route('admin.connectors.enable-catalog', ['connector' => 'meta']))
            ->assertSee(__('connectors.confirm_enable_catalog', ['path' => 'productos']))
            ->assertSee('data-confirm=', false)
            ->assertDontSee(route('admin.connectors.activate', ['connector' => 'meta']));
    }

    public function test_editor_gets_403_everywhere_and_no_sidebar_entry(): void
    {
        $this->actingAs($this->editor);

        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.connectors.index'));
        $this->get(route('admin.connectors.index'))->assertForbidden();
        $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertForbidden();
        foreach (['activate', 'deactivate', 'rotate', 'enable-catalog'] as $action) {
            $this->post(route('admin.connectors.'.$action, ['connector' => 'meta']))->assertForbidden();
        }
        $this->put(route('admin.connectors.settings', ['connector' => 'meta']), ['brand' => 'X'])->assertForbidden();

        $this->assertSame('off', app(ShopModule::class)->mode());
        $this->assertNull(app(ConnectorRegistry::class)->record('meta'));
    }

    public function test_unknown_connector_is_a_404(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.connectors.show', ['connector' => 'google']))->assertNotFound();
        $this->post(route('admin.connectors.activate', ['connector' => 'google']))->assertNotFound();
    }

    public function test_card_button_turns_the_catalog_on_and_returns_to_the_card(): void
    {
        $this->actingAs($this->admin);
        $this->get('/productos')->assertNotFound();

        $this->from(route('admin.connectors.index'))
            ->post(route('admin.connectors.enable-catalog', ['connector' => 'meta']))
            ->assertRedirect(route('admin.connectors.index'))
            ->assertSessionHas('success', __('connectors.catalog_enabled'));

        $this->assertSame('catalog', app(ShopModule::class)->stored());
        $this->assertTrue(app(ShopModule::class)->catalog());
        $this->get('/productos')->assertOk();
        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee('data-status="available"', false)
            ->assertSee(__('connectors.meta_action_activate'))
            ->assertDontSee(route('admin.connectors.enable-catalog', ['connector' => 'meta']));
    }

    public function test_limit_off_explains_without_a_button_and_refuses_the_action(): void
    {
        $this->useShop('off', 'off');
        $this->actingAs($this->admin);

        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee(__('connectors.requirement_shop_unavailable'))
            ->assertDontSee(__('connectors.action_enable_catalog'))
            ->assertDontSee(route('admin.connectors.enable-catalog', ['connector' => 'meta']));

        $this->from(route('admin.connectors.index'))
            ->post(route('admin.connectors.enable-catalog', ['connector' => 'meta']))
            ->assertRedirect(route('admin.connectors.index'))
            ->assertSessionHasErrors('connector');
        $this->assertFalse(app(ShopModule::class)->catalog());
    }

    public function test_available_card_counts_items_and_activating_connects(): void
    {
        $this->useShop('catalog');
        $this->source()->items = [FakeCatalogSource::item('p1'), FakeCatalogSource::item('p2', ['price' => null])];
        $this->actingAs($this->admin);

        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee(trans_choice('connectors.published_count', 2, ['count' => 2]))
            ->assertSee(trans_choice('connectors.would_include', 1, ['count' => 1]));

        $this->post(route('admin.connectors.activate', ['connector' => 'meta']))
            ->assertRedirect(route('admin.connectors.show', ['connector' => 'meta']))
            ->assertSessionHas('success', __('connectors.activated'));

        $this->assertSame(ConnectorStatus::Connected, app(ConnectorRegistry::class)->status('meta'));
        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee('data-status="connected"', false)
            ->assertSee(__('connectors.status_connected'))
            ->assertSee(trans_choice('connectors.included_count', 1, ['count' => 1]))
            ->assertSee(trans_choice('connectors.skipped_count', 1, ['count' => 1]))
            ->assertSee(__('connectors.meta_never_fetched'));
    }

    public function test_activating_with_the_shop_off_is_refused(): void
    {
        $this->actingAs($this->admin);

        $this->from(route('admin.connectors.index'))
            ->post(route('admin.connectors.activate', ['connector' => 'meta']))
            ->assertRedirect(route('admin.connectors.index'))
            ->assertSessionHasErrors('connector');
        $this->assertNull(app(ConnectorRegistry::class)->record('meta'));
    }

    public function test_empty_catalog_warns_and_links_to_new_product_but_still_activates(): void
    {
        $this->useShop('catalog');
        $this->actingAs($this->admin);

        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee(__('connectors.no_products'))
            ->assertSee(route('admin.products.create'))
            ->assertSee(route('admin.connectors.activate', ['connector' => 'meta']));
    }

    public function test_detail_shows_the_feed_url_with_copy_and_the_guide(): void
    {
        $record = $this->connected();
        $this->actingAs($this->admin);

        $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk()
            ->assertSee($record->feedUrl())
            ->assertSee('data-copy-target="feed-url"', false)
            ->assertSee(__('connectors.action_copy'))
            ->assertSee('data-copied="'.__('connectors.copied').'"', false)
            ->assertSee(__('connectors.action_rotate'))
            ->assertSee(__('connectors.confirm_rotate'))
            ->assertSee(__('connectors.guide_title'))
            ->assertSee('https://business.facebook.com/commerce_manager', false)
            ->assertSee('https://business.facebook.com/business/help/2041876302542944', false)
            ->assertSee(__('connectors.guide_step_data_source'))
            ->assertSee(__('connectors.guide_step_name_currency', ['currency' => 'PYG']))
            ->assertSee(__('connectors.meta_action_deactivate'));
    }

    public function test_rotating_invalidates_the_previous_url(): void
    {
        $record = $this->connected();
        $oldToken = $record->plainToken();
        $oldUrl = $record->feedUrl();
        $this->actingAs($this->admin);

        $this->post(route('admin.connectors.rotate', ['connector' => 'meta']))
            ->assertRedirect(route('admin.connectors.show', ['connector' => 'meta']))
            ->assertSessionHas('success', __('connectors.token_rotated'));

        $fresh = app(ConnectorRegistry::class)->record('meta');
        $this->assertNotSame($oldToken, $fresh->plainToken());
        $this->assertNull(app(ConnectorRegistry::class)->feedRecord('meta', $oldToken));
        $this->assertNotNull(app(ConnectorRegistry::class)->feedRecord('meta', $fresh->plainToken()));
        $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk()
            ->assertSee($fresh->feedUrl())
            ->assertDontSee($oldUrl);
    }

    public function test_settings_are_saved_and_a_stale_revision_is_a_conflict(): void
    {
        $record = $this->connected();
        $this->actingAs($this->admin);
        $show = route('admin.connectors.show', ['connector' => 'meta']);
        $revision = $this->operations()->revision($record);

        $this->get($show)->assertOk()
            ->assertSee('name="expected_revision" value="'.$revision.'"', false)
            ->assertSee('name="brand"', false)
            ->assertSee('<select id="setting-condition" name="condition"', false)
            ->assertSee(__('connectors.condition_refurbished'));

        $this->from($show)->put(route('admin.connectors.settings', ['connector' => 'meta']), ['brand' => 'Acme', 'condition' => 'used', 'expected_revision' => $revision])
            ->assertRedirect($show)
            ->assertSessionHas('success', __('connectors.settings_saved'));
        $this->assertSame(['brand' => 'Acme', 'condition' => 'used'], app(ConnectorRegistry::class)->settings('meta'));

        // The revision read before saving is stale now: nothing changes and the form explains.
        $this->from($show)->put(route('admin.connectors.settings', ['connector' => 'meta']), ['brand' => 'Otra', 'condition' => 'new', 'expected_revision' => $revision])
            ->assertRedirect($show)
            ->assertSessionHasErrors(['expected_revision' => __('connectors.revision_conflict')]);
        $this->assertSame('Acme', app(ConnectorRegistry::class)->settings('meta')['brand']);

        $fresh = $this->operations()->revision(app(ConnectorRegistry::class)->record('meta'));
        $this->from($show)->put(route('admin.connectors.settings', ['connector' => 'meta']), ['brand' => 'Acme', 'condition' => 'broken', 'expected_revision' => $fresh])
            ->assertRedirect($show)
            ->assertSessionHasErrors('condition');
        $this->get($show)->assertSee('value="Acme"', false);
    }

    public function test_preview_lists_sample_xml_and_every_skipped_item_with_reason_and_edit_link(): void
    {
        $this->connected();
        $this->source()->items = [
            FakeCatalogSource::item('p1', ['title' => 'Mate de calabaza', 'brand' => null]),
            FakeCatalogSource::item('p2', ['title' => 'Termo sin precio', 'price' => null]),
            FakeCatalogSource::item('p3', ['title' => 'Bombilla sin foto', 'imageMediaId' => null, 'price' => null]),
        ];
        $this->actingAs($this->admin);

        $response = $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk()
            ->assertSee(__('connectors.preview_title'))
            ->assertSee(trans_choice('connectors.included_count', 1, ['count' => 1]))
            ->assertSee(trans_choice('connectors.skipped_count', 2, ['count' => 2]))
            ->assertSee('Mate de calabaza')
            ->assertSee('150000 PYG')
            ->assertSee(__('connectors.availability_in_stock'))
            ->assertSee('Default brand')
            ->assertSee(__('connectors.action_view_xml'))
            ->assertSee("p1\tMate de calabaza\tDefault brand", false)
            ->assertSee(__('connectors.skipped_title'))
            ->assertSee('Termo sin precio')
            ->assertSee('Bombilla sin foto')
            ->assertSee(__('connectors.warning_missing_price'))
            ->assertSee(__('connectors.warning_missing_image'))
            ->assertSee('/admin/products/p2/edit')
            ->assertSee('/admin/products/p3/edit')
            ->assertSee(__('connectors.action_edit_product'))
            ->assertSee(__('connectors.notices_title'))
            ->assertSee(__('connectors.warning_default_brand'));

        // Skipped items never appear in the XML sample.
        $this->assertStringNotContainsString("p2\tTermo", $response->getContent());
    }

    public function test_preview_needs_the_catalog(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk()
            ->assertSee(__('connectors.preview_unavailable'))
            ->assertSee(__('connectors.action_enable_catalog'))
            ->assertSee(__('connectors.guide_title'));
    }

    public function test_last_meta_fetch_is_visible_on_card_and_detail(): void
    {
        $record = $this->connected();
        $this->actingAs($this->admin);
        $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertSee(__('connectors.meta_never_fetched'))->assertSee(__('connectors.never_fetched'));

        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(14, 5));
        $this->operations()->recordFetch($record, 'fakebot/1.0 (+https://example.test)');
        $this->travelTo(now()->addHours(2));

        $this->get(route('admin.connectors.index'))->assertOk()->assertSee('23/11/2026 14:05')->assertDontSee(__('connectors.meta_never_fetched'));
        $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk()
            ->assertSee(__('connectors.last_meta_fetch', ['ago' => now()->subHours(2)->diffForHumans(), 'date' => '23/11/2026 14:05']))
            ->assertSee(__('connectors.last_fetch', ['date' => '23/11/2026 14:05', 'agent' => 'fakebot/1.0 (+https://example.test)']));
    }

    public function test_connected_then_shop_off_pauses_with_the_same_button_and_deactivate_keeps_data(): void
    {
        $record = $this->connected();
        $this->useShop('off');
        $this->actingAs($this->admin);

        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee('data-status="needs_action"', false)
            ->assertSee(__('connectors.meta_paused_shop_off'))
            ->assertSee(__('connectors.action_enable_catalog'));

        $this->post(route('admin.connectors.deactivate', ['connector' => 'meta']))
            ->assertRedirect(route('admin.connectors.show', ['connector' => 'meta']))
            ->assertSessionHas('success', __('connectors.deactivated'));
        $fresh = app(ConnectorRegistry::class)->record('meta');
        $this->assertFalse($fresh->enabled);
        $this->assertSame($record->token_hash, $fresh->token_hash);
    }

    public function test_full_and_essential_render_the_same_screens(): void
    {
        foreach (['full', 'essential'] as $profile) {
            $this->useAdminProfile($profile);
            $this->useShop('catalog');
            $this->actingAs($this->admin);

            $this->get(route('admin.connectors.index'))->assertOk()->assertSee(__('connectors.meta_label'));
            $this->post(route('admin.connectors.activate', ['connector' => 'meta']))->assertRedirect(route('admin.connectors.show', ['connector' => 'meta']));
            $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk()
                ->assertSee('data-copy-target="feed-url"', false)
                ->assertSee(__('connectors.guide_title'))
                ->assertSee(__('connectors.preview_title'));
            $this->post(route('admin.connectors.deactivate', ['connector' => 'meta']))->assertRedirect();
        }
    }

    public function test_turning_the_shop_off_warns_about_the_active_catalog_connectors(): void
    {
        $this->actingAs($this->admin);
        $this->useShop('catalog');
        $this->get(route('admin.connectors.index'))->assertOk(); // the panel locale applies to the texts below
        $warning = __('shop.module_confirm_off_connectors', ['connectors' => __('connectors.meta_label')]);

        // Not active yet: nothing to warn about.
        $this->get(route('admin.settings.shop', ['mode' => 'off']))->assertOk()
            ->assertSee('data-shop-module-confirm="off"', false)
            ->assertDontSee('data-shop-module-connectors', false)
            ->assertDontSee($warning);

        $this->operations()->activate($this->admin, 'meta');
        $this->get(route('admin.settings.shop', ['mode' => 'off']))->assertOk()
            ->assertSee('data-shop-module-connectors', false)
            ->assertSee($warning)
            ->assertSee(__('shop.module_confirm_off_connectors_help'));
        $this->assertSame(['meta'], array_keys(app(ConnectorRegistry::class)->activeNeedingCatalog()));

        // A connector that does not read the catalog is not listed.
        $this->fake->requiresCatalog = false;
        $this->assertSame([], app(ConnectorRegistry::class)->activeNeedingCatalog());
        $this->fake->requiresCatalog = true;

        // Deactivated: no warning again.
        $this->operations()->deactivate($this->admin, 'meta');
        $this->get(route('admin.settings.shop', ['mode' => 'off']))->assertOk()->assertDontSee($warning);
    }

    public function test_turning_the_shop_off_counts_products_like_the_product_list(): void
    {
        Locale::create(['code' => 'en', 'name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        $this->useShop('catalog');
        $group = (string) Str::uuid();
        Product::create(['title' => 'Mate', 'slug' => 'mate', 'locale' => 'es', 'status' => 'published', 'translation_group_id' => $group]);
        Product::create(['title' => 'Gourd', 'slug' => 'gourd', 'locale' => 'en', 'status' => 'published', 'translation_group_id' => $group]);
        Product::create(['title' => 'Termo', 'slug' => 'termo', 'locale' => 'es', 'status' => 'draft']);
        $this->actingAs($this->admin);

        // Two products in the base language (one published, one draft); the English row is a translation.
        $this->get(route('admin.settings.shop', ['mode' => 'off']))->assertOk()
            ->assertSee(trans_choice('shop.module_confirm_off_kept', 2, ['count' => 2]));
    }

    public function test_available_card_counts_products_apart_from_feed_items(): void
    {
        $this->useShop('catalog');
        $this->source()->items = [
            FakeCatalogSource::item('p1'),
            FakeCatalogSource::item('REM-M', ['groupId' => 'p2']),
            FakeCatalogSource::item('REM-L', ['groupId' => 'p2']),
        ];
        $this->actingAs($this->admin);

        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee(trans_choice('connectors.published_count', 2, ['count' => 2]))
            ->assertSee(trans_choice('connectors.feed_items_count', 3, ['count' => 3]))
            ->assertDontSee(trans_choice('connectors.published_count', 3, ['count' => 3]));

        // Without variants the item count is not repeated.
        $this->source()->items = [FakeCatalogSource::item('p1')];
        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee(trans_choice('connectors.published_count', 1, ['count' => 1]))
            ->assertDontSee(trans_choice('connectors.feed_items_count', 1, ['count' => 1]));
    }

    public function test_enable_catalog_message_follows_the_next_step(): void
    {
        // Active connector paused by the shop: it resumes, nothing to load.
        $this->connected();
        $this->source()->items = [FakeCatalogSource::item('p1')];
        $this->useShop('off');
        $this->actingAs($this->admin);

        $this->from(route('admin.connectors.index'))->post(route('admin.connectors.enable-catalog', ['connector' => 'meta']))
            ->assertSessionHas('success', __('connectors.catalog_resumed', ['connector' => __('connectors.meta_label')]));

        // Not active, products already loaded: activate the connector.
        $this->operations()->deactivate($this->admin, 'meta');
        $this->useShop('off');
        $this->from(route('admin.connectors.index'))->post(route('admin.connectors.enable-catalog', ['connector' => 'meta']))
            ->assertSessionHas('success', __('connectors.catalog_enabled_ready'));

        // Empty catalog: load products first.
        $this->source()->items = [];
        $this->useShop('off');
        $this->from(route('admin.connectors.index'))->post(route('admin.connectors.enable-catalog', ['connector' => 'meta']))
            ->assertSessionHas('success', __('connectors.catalog_enabled'));
    }

    public function test_paused_card_says_paused_and_a_missing_step_says_so(): void
    {
        $this->actingAs($this->admin);
        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee(__('connectors.status_needs_action'))
            ->assertDontSee(__('connectors.status_paused'));

        $this->connected();
        $this->useShop('off');
        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee('data-status="needs_action"', false)
            ->assertSee(__('connectors.status_paused'))
            ->assertDontSee(__('connectors.status_needs_action'));
    }

    public function test_a_skipped_item_is_not_repeated_in_the_notices(): void
    {
        $this->connected();
        $this->source()->items = [
            FakeCatalogSource::item('p1', ['title' => 'Mate sin marca', 'brand' => null]),
            FakeCatalogSource::item('p2', ['title' => 'Termo sin precio', 'price' => null, 'brand' => null]),
        ];

        $preview = $this->operations()->preview('meta');
        $this->assertSame(['p1'], array_map(fn ($warning) => $warning->itemId, $preview->notices()));
        $this->assertSame(['p2'], array_map(fn ($warning) => $warning->itemId, $preview->skippedWarnings()));

        $this->actingAs($this->admin);
        $content = $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk()->getContent();
        $notices = substr($content, strpos($content, 'data-warnings="notices"'));
        $this->assertStringContainsString('Mate sin marca', $notices);
        $this->assertStringNotContainsString('Termo sin precio', $notices);
    }

    public function test_a_fetch_under_a_minute_ago_reads_a_moment_ago(): void
    {
        $record = $this->connected();
        $this->operations()->recordFetch($record, 'fakebot/1.0');
        $this->actingAs($this->admin);

        $this->get(route('admin.connectors.index'))->assertOk()
            ->assertSee(__('connectors.last_meta_fetch', ['ago' => __('connectors.fetched_just_now'), 'date' => now()->format('d/m/Y H:i')]));
    }

    public function test_detail_names_the_connector_once(): void
    {
        $this->connected();
        $this->actingAs($this->admin);

        $content = $this->get(route('admin.connectors.show', ['connector' => 'meta']))->assertOk()->getContent();
        $main = substr($content, (int) strpos($content, '<main'));
        $this->assertSame(1, substr_count($main, e(__('connectors.meta_label'))));
    }
}
