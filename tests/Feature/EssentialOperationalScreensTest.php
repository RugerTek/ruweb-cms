<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Policies\PostPolicy;
use App\Support\AdminCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class EssentialOperationalScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cms.admin_profile' => 'essential']);
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true]);
    }

    public function test_operational_screens_keep_the_essential_entrypoint_and_navigation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['/admin', '/admin/media', '/admin/media-health', '/admin/settings', '/admin/languages', '/admin/analytics', '/admin/email', '/admin/users', '/admin/profile', '/admin/claude-mcp', '/admin/developer-tools', '/admin/submissions/contact'] as $url) {
            $response = $this->get($url)->assertOk();
            $response->assertSee('resources/js/admin-essential.js')
                ->assertDontSee('resources/js/admin.js')
                ->assertDontSee('href="'.url('/admin/menus').'"', false)
                ->assertDontSee('href="'.url('/admin/header').'"', false)
                ->assertDontSee('href="'.url('/admin/footer').'"', false)
                ->assertDontSee('href="'.url('/admin/taxonomies/category').'"', false);
        }
    }

    public function test_settings_rich_text_stays_an_escaped_native_field_and_retains_sanitization(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $setting = Setting::create(['key' => 'operational_note', 'value' => '<p>Existing <strong>format</strong></p>', 'type' => 'rich_text', 'group' => 'general']);
        $this->get('/admin/settings')->assertOk()
            ->assertSee('&lt;p&gt;Existing &lt;strong&gt;format&lt;/strong&gt;&lt;/p&gt;', false)
            ->assertDontSee('data-rich-text', false);
        $this->put('/admin/settings', ['settings' => ['operational_note' => '<p>Updated</p><script>alert(1)</script>']])->assertRedirect();
        $this->assertSame('<p>Updated</p>', $setting->fresh()->value);
        config(['cms.admin_profile' => 'full']);
        $this->get('/admin/settings')->assertOk()->assertSee('data-rich-text="short-description"', false);
    }

    public function test_registry_navigation_and_policy_control_collection_visibility(): void
    {
        config(['cms.collections.articles' => ['model' => Post::class, 'label' => 'admin.posts']]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->get('/admin')->assertOk()->assertSee('href="'.route('admin.content.index', 'articles').'"', false);
        $this->get('/admin/content/articles')->assertOk();
        Gate::policy(Post::class, EssentialDeniedPostPolicy::class);
        $this->get('/admin')->assertOk()->assertDontSee('href="'.route('admin.content.index', 'articles').'"', false);
        $this->get('/admin/content/articles')->assertForbidden();
    }

    public function test_developer_tools_stays_on_diagnostics_even_when_requesting_an_advanced_tab(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['system', 'migration', 'redirects', 'not-found'] as $tab) {
            $this->get('/admin/developer-tools?tab='.$tab)->assertOk()->assertViewHas('activeTab', 'system')
                ->assertDontSee('action="'.route('admin.developer-tools.download').'"', false)
                ->assertDontSee('action="'.route('admin.developer-tools.upload').'"', false)
                ->assertDontSee('tab=migration', false)->assertDontSee('tab=redirects', false)->assertDontSee('tab=not-found', false);
        }
    }

    public function test_diagnostics_and_calendar_link_to_content_status(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $page = Page::create(['title' => 'Scheduled operational fixture', 'slug' => 'operational-fixture', 'template_key' => 'default', 'locale' => 'es', 'status' => 'published', 'published_at' => now()->addDay(), 'content_json' => []]);
        $destination = app(AdminCapabilities::class)->contentUrl($page);
        foreach (['/admin/editorial-control', '/admin/editorial-calendar?month='.now()->addDay()->format('Y-m')] as $url) {
            $this->get($url)->assertOk()->assertSee($destination, false)
                ->assertDontSee('href="'.route('admin.pages.edit', $page).'"', false);
        }
    }

    public function test_essential_retains_role_restrictions(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'editor']));
        foreach (['/admin/media', '/admin/profile', '/admin/claude-mcp', '/admin/submissions/contact'] as $url) {
            $this->get($url)->assertOk();
        }
        foreach (['/admin/settings', '/admin/users', '/admin/languages', '/admin/analytics', '/admin/email', '/admin/developer-tools'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }
}

class EssentialDeniedPostPolicy extends PostPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }
}
