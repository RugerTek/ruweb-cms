<?php

namespace Tests\Feature;

use App\Models\NotFoundHit;
use App\Models\Page;
use App\Models\Redirect;
use App\Models\User;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** 301/302 rules, the 404 monitor and the admin screen behind them (docs/operations/redirects.md). */
class RedirectsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingsSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_unmatched_path_follows_an_active_rule(): void
    {
        Redirect::create([
            'source_path' => '/servicios-antiguos',
            'target_url' => '/servicios',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $this->get('/servicios-antiguos')->assertRedirect('/servicios')->assertStatus(301);

        $rule = Redirect::firstOrFail();
        $this->assertSame(1, $rule->hit_count);
        $this->assertNotNull($rule->last_used_at);
    }

    public function test_inactive_rule_is_ignored_and_the_404_is_recorded(): void
    {
        Redirect::create([
            'source_path' => '/promo',
            'target_url' => '/promociones',
            'status_code' => 301,
            'is_active' => false,
        ]);

        $this->get('/promo')->assertNotFound();

        $this->assertDatabaseHas('not_found_hits', ['path' => '/promo', 'hit_count' => 1]);
        $this->assertSame(0, Redirect::firstOrFail()->hit_count);
    }

    public function test_trailing_slash_and_query_string_do_not_break_the_match(): void
    {
        Redirect::create([
            'source_path' => '/vieja',
            'target_url' => '/nueva',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $this->get('/vieja/?utm_source=newsletter')
            ->assertRedirect('/nueva?utm_source=newsletter');
    }

    public function test_a_rule_wins_over_a_live_page(): void
    {
        Page::create([
            'locale' => 'es',
            'title' => 'Contacto',
            'slug' => 'contacto',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['heading' => 'Contacto']]],
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $this->get('/contacto')->assertOk();

        Redirect::create([
            'source_path' => '/contacto',
            'target_url' => '/contactanos',
            'status_code' => 302,
            'is_active' => true,
        ]);

        $this->get('/contacto')->assertRedirect('/contactanos')->assertStatus(302);
    }

    public function test_repeated_404s_increment_a_single_row(): void
    {
        $this->get('/no-existe');
        $this->get('/no-existe/');

        $this->assertSame(1, NotFoundHit::count());
        $this->assertSame(2, NotFoundHit::firstOrFail()->hit_count);
    }

    public function test_admin_paths_are_never_monitored(): void
    {
        $this->actingAs($this->admin())->get('/admin/no-existe')->assertNotFound();

        $this->assertDatabaseCount('not_found_hits', 0);
    }

    public function test_only_admins_reach_the_screen(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);
        $url = route('admin.developer-tools.index', ['tab' => 'redirects']);

        $this->actingAs($editor)->get($url)->assertForbidden();
        $this->actingAs($this->admin())->get($url)->assertOk();
    }

    public function test_rules_and_the_monitor_are_separate_tabs(): void
    {
        Redirect::create([
            'source_path' => '/vieja',
            'target_url' => '/nueva',
            'status_code' => 301,
            'is_active' => true,
        ]);
        $this->get('/una-ruta-inventada');

        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.developer-tools.index', ['tab' => 'redirects']))
            ->assertOk()
            ->assertSee('/vieja')
            ->assertDontSee('/una-ruta-inventada');

        $this->actingAs($admin)
            ->get(route('admin.developer-tools.index', ['tab' => 'not-found']))
            ->assertOk()
            ->assertSee('/una-ruta-inventada')
            ->assertDontSee('/vieja');
    }

    public function test_a_404_row_prefills_the_rule_form(): void
    {
        $this->get('/tienda/producto-viejo');

        $this->actingAs($this->admin())
            ->get(route('admin.developer-tools.index', ['tab' => 'redirects', 'source' => '/tienda/producto-viejo']))
            ->assertOk()
            ->assertSee('value="/tienda/producto-viejo"', false);
    }

    public function test_admin_creates_a_rule_with_a_normalized_source(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.redirects.store'), [
                'source_path' => 'https://legacy.example.com/vieja/?ref=1',
                'target_url' => '/nueva',
                'status_code' => 301,
                'is_active' => 1,
            ])
            ->assertRedirect(route('admin.developer-tools.index', ['tab' => 'redirects']));

        $this->assertDatabaseHas('redirects', ['source_path' => '/vieja', 'target_url' => '/nueva']);
    }

    public function test_a_rule_pointing_at_itself_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.redirects.store'), [
                'source_path' => '/vieja',
                'target_url' => '/vieja/',
                'status_code' => 301,
            ])
            ->assertSessionHasErrors('source_path');

        $this->assertDatabaseCount('redirects', 0);
    }

    public function test_a_rule_that_closes_a_loop_is_rejected(): void
    {
        Redirect::create([
            'source_path' => '/b',
            'target_url' => '/a',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.redirects.store'), [
                'source_path' => '/a',
                'target_url' => '/b',
                'status_code' => 301,
            ])
            ->assertSessionHasErrors('source_path');

        $this->assertDatabaseCount('redirects', 1);
    }

    public function test_csv_import_creates_updates_and_skips_rows(): void
    {
        Redirect::create([
            'source_path' => '/existente',
            'target_url' => '/viejo-destino',
            'status_code' => 301,
            'is_active' => true,
        ]);

        $csv = "source_path,target_url,status_code,is_active\n"
            ."/uno,/uno-nuevo,301,1\n"
            ."/existente,/nuevo-destino,302,1\n"
            ."/roto,/roto,301,1\n";

        $this->actingAs($this->admin())
            ->post(route('admin.redirects.import'), [
                'redirects_csv' => UploadedFile::fake()->createWithContent('redirects.csv', $csv),
            ])
            ->assertRedirect(route('admin.developer-tools.index', ['tab' => 'redirects']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('redirects', ['source_path' => '/uno', 'target_url' => '/uno-nuevo']);
        $this->assertDatabaseHas('redirects', ['source_path' => '/existente', 'target_url' => '/nuevo-destino', 'status_code' => 302]);
        $this->assertDatabaseMissing('redirects', ['source_path' => '/roto']);
    }

    public function test_admin_clears_the_not_found_monitor(): void
    {
        $this->get('/no-existe');

        $this->actingAs($this->admin())
            ->delete(route('admin.redirects.not-found.clear'))
            ->assertRedirect(route('admin.developer-tools.index', ['tab' => 'not-found']));

        $this->assertDatabaseCount('not_found_hits', 0);
    }
}
