<?php

namespace Tests\Feature;

use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Services\Pages\PageOperations;
use App\Services\Posts\PostOperations;
use App\Support\SettingDefaults;
use App\Support\SiteTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Storage stays UTC; people read and type dates in the site zone (docs/concepts/time-zones.md). */
class SiteTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-26 16:17 UTC is 13:17 in Asunción (UTC-3).
        $this->travelTo(Carbon::parse('2026-11-26 16:17:00', 'UTC'));
    }

    private function rawDate(string $table, int $id, string $column): ?string
    {
        $value = DB::table($table)->where('id', $id)->value($column);

        return $value === null ? null : substr((string) $value, 0, 19);
    }

    public function test_storage_stays_utc_and_the_site_zone_defaults_to_asuncion(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('America/Asuncion', Setting::get('site_timezone'));
        $this->assertSame('America/Asuncion', SiteTime::timezone());
        $this->assertSame('America/Asuncion', SettingDefaults::for('site_timezone')['value']);
        $this->assertSame('26/11/2026 13:17', SiteTime::format(now()));
        $this->assertSame('26/11/2026 13:17', SiteTime::format('2026-11-26 16:17:00'));
    }

    public function test_env_value_seeds_the_setting_and_an_invalid_value_falls_back(): void
    {
        config(['cms.display_timezone' => 'Europe/Madrid']);
        $this->assertSame('Europe/Madrid', SiteTime::configured());
        $this->assertSame('Europe/Madrid', SettingDefaults::for('site_timezone')['value']);

        config(['cms.display_timezone' => 'Mars/Olympus']);
        $this->assertSame(SiteTime::DEFAULT_TIMEZONE, SiteTime::configured());

        DB::table('settings')->where('key', 'site_timezone')->update(['value' => 'Not/AZone']);
        Setting::clearCache();
        $this->assertSame(SiteTime::DEFAULT_TIMEZONE, SiteTime::timezone());
    }

    public function test_typed_values_without_offset_are_read_in_the_site_zone(): void
    {
        $this->assertSame('2026-12-01T12:00:00Z', SiteTime::toStorage('2026-12-01T09:00'));
        $this->assertSame('2026-12-01T12:00:00Z', SiteTime::toStorage('2026-12-01 09:00:00'));
        // An explicit offset wins, and converting twice changes nothing.
        $this->assertSame('2026-12-01T09:00:00Z', SiteTime::toStorage('2026-12-01T09:00:00Z'));
        $this->assertSame('2026-12-01T12:00:00Z', SiteTime::toStorage('2026-12-01T09:00:00-03:00'));
        $this->assertSame('2026-12-01T12:00:00Z', SiteTime::toStorage(SiteTime::toStorage('2026-12-01T09:00')));
        $this->assertSame('not a date', SiteTime::toStorage('not a date'));
        $this->assertNull(SiteTime::toStorage(null));
        $this->assertSame('', SiteTime::toStorage(''));
    }

    public function test_scheduled_post_round_trips_through_the_panel_form(): void
    {
        // The post form is the Full editor; Essential schedules through the same operations (next test).
        $this->useAdminProfile('full');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.posts.store'), [
            'title' => 'Programado',
            'slug' => 'programado',
            'content' => 'Cuerpo',
            'status' => 'published',
            'published_at' => '2026-12-01T09:00',
            'expires_at' => '2026-12-31T23:30',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $post = Post::where('slug', 'programado')->firstOrFail();
        $this->assertSame('2026-12-01 12:00:00', $this->rawDate('posts', $post->id, 'published_at'));
        $this->assertSame('2027-01-01 02:30:00', $this->rawDate('posts', $post->id, 'expires_at'));
        $this->assertFalse($post->isPublished());

        $this->actingAs($admin)->get(route('admin.posts.edit', $post))
            ->assertOk()
            ->assertSee('value="2026-12-01T09:00"', false)
            ->assertSee('value="2026-12-31T23:30"', false);

        // Saving again the values the form showed keeps the stored instant (same shared operation as the panel).
        $this->assertSame('2026-12-01T09:00', SiteTime::input($post->published_at));
        app(PostOperations::class)->save($admin, [
            'title' => 'Programado',
            'slug' => 'programado',
            'content' => 'Cuerpo',
            'status' => 'published',
            'published_at' => SiteTime::input($post->published_at),
            'expires_at' => SiteTime::input($post->expires_at),
        ], $post);
        $this->assertSame('2026-12-01 12:00:00', $this->rawDate('posts', $post->id, 'published_at'));
        $this->assertSame('2027-01-01 02:30:00', $this->rawDate('posts', $post->id, 'expires_at'));

        // It goes live at 09:00 Asunción, not at 09:00 UTC.
        $this->travelTo(Carbon::parse('2026-12-01 11:59:00', 'UTC'));
        $this->assertFalse($post->fresh()->isPublished());
        $this->travelTo(Carbon::parse('2026-12-01 12:00:01', 'UTC'));
        $this->assertTrue($post->fresh()->isPublished());
    }

    public function test_publication_operations_read_typed_dates_in_the_site_zone(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Locale::create(['code' => 'es', 'name' => 'es', 'is_base' => true, 'is_active' => true]);

        $posts = app(PostOperations::class);
        $post = $posts->save($admin, ['title' => 'Op', 'slug' => 'op'], draftOnly: true);
        $posts->schedule($admin, $post, '2026-12-02 08:00');
        $this->assertSame('2026-12-02 11:00:00', $this->rawDate('posts', $post->id, 'published_at'));
        $posts->setExpiry($admin, $post->fresh(), '2026-12-03T10:00:00Z');
        $this->assertSame('2026-12-03 10:00:00', $this->rawDate('posts', $post->id, 'expires_at'));

        $page = Page::create(['title' => 'P', 'slug' => 'p', 'locale' => 'es', 'template_key' => array_key_first(config('cms.templates')), 'status' => 'draft', 'content_json' => []]);
        $pages = app(PageOperations::class);
        $pages->setPublication($admin, $page, '2026-12-05T07:30');
        $this->assertSame('2026-12-05 10:30:00', $this->rawDate('pages', $page->id, 'published_at'));
        $pages->setExpiry($admin, $page->fresh(), '2026-12-06T07:30:00-03:00');
        $this->assertSame('2026-12-06 10:30:00', $this->rawDate('pages', $page->id, 'expires_at'));
    }

    public function test_panel_lists_and_emails_show_the_site_zone(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $submission = ContactSubmission::create([
            'name' => 'Ana', 'email' => 'ana@example.com', 'subject' => 'Hola',
            'message' => 'Mensaje de prueba.', 'locale' => 'es', 'ip' => '127.0.0.1',
        ]);
        $this->assertSame('2026-11-26 16:17:00', $this->rawDate('contact_submissions', $submission->id, 'created_at'));

        $this->actingAs($admin)->get(route('admin.submissions.index', ['form' => 'contact']))
            ->assertOk()->assertSee('26/11/2026 13:17')->assertDontSee('26/11/2026 16:17');
        $this->actingAs($admin)->get(route('admin.submissions.show', ['form' => 'contact', 'submission' => $submission->id]))
            ->assertOk()->assertSee('26/11/2026 13:17');

        $mail = (new ContactSubmissionReceived($submission))->render();
        $this->assertStringContainsString('26/11/2026 13:17', $mail);
        $this->assertStringNotContainsString('26/11/2026 16:17', $mail);
    }

    public function test_submission_day_filters_use_the_site_day(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        // 01:30 UTC on the 27th is still the 26th in Asunción.
        $this->travelTo(Carbon::parse('2026-11-27 01:30:00', 'UTC'));
        ContactSubmission::create(['name' => 'Noche', 'email' => 'n@example.com', 'subject' => 'Tarde', 'message' => 'Mensaje nocturno.', 'locale' => 'es', 'ip' => '127.0.0.1']);

        $this->actingAs($admin)->get(route('admin.submissions.index', ['form' => 'contact', 'from' => '2026-11-26', 'to' => '2026-11-26']))
            ->assertOk()->assertSee('Noche');
        $this->actingAs($admin)->get(route('admin.submissions.index', ['form' => 'contact', 'from' => '2026-11-27']))
            ->assertOk()->assertDontSee('Noche');
    }

    public function test_settings_screen_saves_a_valid_zone_and_rejects_others(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Zona horaria')
            ->assertSee('<option value="America/Asuncion" selected', false)
            ->assertSee('<option value="Europe/Madrid"', false);

        $this->actingAs($admin)->from(route('admin.settings.index'))->put(route('admin.settings.update'), ['settings' => ['site_timezone' => 'Mars/Olympus']])
            ->assertSessionHasErrors('settings.site_timezone');
        $this->assertSame('America/Asuncion', Setting::get('site_timezone'));

        $this->actingAs($admin)->put(route('admin.settings.update'), ['settings' => ['site_timezone' => 'Europe/Madrid']])
            ->assertRedirect(route('admin.settings.index'));
        Setting::clearCache();
        $this->assertSame('Europe/Madrid', SiteTime::timezone());
        $this->assertSame('26/11/2026 17:17', SiteTime::format(now()));
    }

    public function test_agents_can_read_and_change_the_zone_through_mcp(): void
    {
        foreach (SettingDefaults::all() as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
        $this->withToken(McpApiKey::issue(User::factory()->create(['role' => 'admin']), 'Zona', 'full', null));

        $rpc = fn (array $args) => $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'settings_update_batch', 'arguments' => $args]]);

        $rpc(['settings' => ['site_timezone' => 'America/Sao_Paulo']])->assertOk()->assertJsonPath('result.isError', false);
        Setting::clearCache();
        $this->assertSame('America/Sao_Paulo', Setting::get('site_timezone'));

        $invalid = $rpc(['settings' => ['site_timezone' => 'Nowhere/City']]);
        $this->assertTrue($invalid->json('result.isError') === true || $invalid->json('error') !== null);
        Setting::clearCache();
        $this->assertSame('America/Sao_Paulo', Setting::get('site_timezone'));
    }

    public function test_public_blog_dates_use_the_site_day(): void
    {
        // Published 01:30 UTC on the 27th: readers in Asunción saw it on the evening of the 26th.
        $this->travelTo(Carbon::parse('2026-11-27 02:00:00', 'UTC'));
        Post::create(['title' => 'Nocturna', 'slug' => 'nocturna', 'content' => 'Cuerpo', 'status' => 'published', 'published_at' => Carbon::parse('2026-11-27 01:30:00', 'UTC')]);

        $this->get(route('blog.index'))->assertOk()->assertSee('26, 2026')->assertDontSee('27, 2026');
        $this->get(route('blog.show', 'nocturna'))->assertOk()->assertSee('26, 2026')->assertDontSee('27, 2026');
    }

    public function test_editorial_calendar_uses_site_month_bounds(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Locale::create(['code' => 'es', 'name' => 'es', 'is_base' => true, 'is_active' => true]);
        // 2026-12-01 01:00 UTC is still November 30 in Asunción.
        $post = Post::create(['title' => 'Fin de mes', 'slug' => 'fin-de-mes', 'locale' => 'es', 'status' => 'published', 'published_at' => Carbon::parse('2026-12-01 01:00:00', 'UTC')]);

        $this->actingAs($admin)->get(route('admin.editorial.calendar', ['month' => '2026-11']))
            ->assertOk()->assertSee('Fin de mes')->assertSee('30/11/2026 22:00');
        $this->actingAs($admin)->get(route('admin.editorial.calendar', ['month' => '2026-12']))
            ->assertOk()->assertDontSee('Fin de mes');
        $this->assertSame('2026-12-01 01:00:00', $this->rawDate('posts', $post->id, 'published_at'));
    }
}
