<?php

namespace Tests\Feature;

use App\Models\ContactSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminSubmissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function submission(array $overrides = []): ContactSubmission
    {
        return ContactSubmission::create(array_merge([
            'name' => 'Ana Pérez', 'email' => 'ana@example.com', 'subject' => 'Soporte',
            'message' => 'Necesito ayuda con mi pedido.', 'locale' => 'es', 'ip' => '127.0.0.1',
        ], $overrides));
    }

    #[DataProvider('adminProfiles')]
    public function test_index_lists_submissions_with_tabs_counts_and_filters(string $profile): void
    {
        $this->useAdminProfile($profile);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->submission();
        $this->submission(['name' => 'Bruno Díaz', 'email' => 'bruno@example.com', 'subject' => 'Presupuesto', 'read_at' => now()]);

        $response = $this->actingAs($admin)->get(route('admin.submissions.index', ['form' => 'contact']));
        $response->assertOk();
        $response->assertSee('Contacto');
        $response->assertSee('Ana Pérez');
        $response->assertSee('Bruno Díaz');

        $this->actingAs($admin)->get(route('admin.submissions.index', ['form' => 'contact', 'status' => 'new']))
            ->assertSee('Ana Pérez')->assertDontSee('Bruno Díaz');

        $this->actingAs($admin)->get(route('admin.submissions.index', ['form' => 'contact', 'search' => 'presupuesto']))
            ->assertSee('Bruno Díaz')->assertDontSee('Ana Pérez');

        $this->actingAs($admin)->get(route('admin.submissions.index', ['form' => 'contact', 'from' => now()->addDay()->toDateString()]))
            ->assertSee('Todavía no hay envíos');
    }

    #[DataProvider('adminProfiles')]
    public function test_show_marks_the_submission_as_read_and_shows_metadata(string $profile): void
    {
        $this->useAdminProfile($profile);
        $editor = User::factory()->create(['role' => 'editor']);
        $submission = $this->submission();

        $response = $this->actingAs($editor)->get(route('admin.submissions.show', ['form' => 'contact', 'submission' => $submission->id]));

        $response->assertOk();
        $response->assertSee('Necesito ayuda con mi pedido.');
        $response->assertSee('127.0.0.1');
        $this->assertNotNull($submission->fresh()->read_at);
    }

    #[DataProvider('adminProfiles')]
    public function test_delete_removes_the_submission(string $profile): void
    {
        $this->useAdminProfile($profile);
        $admin = User::factory()->create(['role' => 'admin']);
        $submission = $this->submission();

        $this->actingAs($admin)->delete(route('admin.submissions.destroy', ['form' => 'contact', 'submission' => $submission->id]))
            ->assertRedirect(route('admin.submissions.index', ['form' => 'contact']));

        $this->assertDatabaseCount('contact_submissions', 0);
    }

    public function test_unknown_form_is_not_found_and_guests_are_kept_out(): void
    {
        // Guests never learn the admin exists: the app answers 404, like every other admin route.
        $this->get(route('admin.submissions.index', ['form' => 'contact']))->assertNotFound();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.submissions.index', ['form' => 'newsletter']))->assertNotFound();
    }

    public function test_sidebar_shows_unread_badge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->submission();
        $this->submission();

        $response = $this->actingAs($admin)->get(route('admin.dashboard'));

        $response->assertSee('Envíos');
        $response->assertSee('rounded-full bg-emerald-500/15 text-emerald-400 text-xs">2', false);
    }

    public function test_security_settings_tab_exposes_recaptcha_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->seed(\Database\Seeders\SettingsSeeder::class);

        $response = $this->actingAs($admin)->get(route('admin.settings.index', ['tab' => 'security']));

        $response->assertOk();
        $response->assertSee('Seguridad');
        $response->assertSee('reCAPTCHA v3: clave del sitio');
        $response->assertSee('name="settings[recaptcha_secret_key]"', false);
    }

    public function test_contact_page_editor_shows_form_section_controls(): void
    {
        $this->useAdminProfile('full');
        $admin = User::factory()->create(['role' => 'admin']);
        \App\Models\Locale::query()->firstOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        $this->seed(\Database\Seeders\ContactPageSeeder::class);
        $page = \App\Models\Page::where('slug', 'contacto')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('admin.pages.edit', $page));

        $response->assertOk();
        $response->assertSee('Opciones del motivo');
        $response->assertSee('data-repeater-key="subject_options"', false);
        $response->assertSee('Ver envíos de contacto');
        $response->assertSee(route('admin.submissions.index', ['form' => 'contact']));
        $response->assertSee('name="content_json[sections][form][notify_email]"', false);
    }
}
