<?php

namespace Tests\Feature;

use App\Mcp\ToolRegistry;
use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Page;
use App\Models\User;
use App\Support\AdminCapabilities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AdminProfilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true]);
    }

    public function test_default_and_invalid_profiles_are_explicit(): void
    {
        $this->assertSame('full', app(AdminCapabilities::class)->profile());
        config(['cms.admin_profile' => 'agent-only']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Invalid cms.admin_profile');
        app(AdminCapabilities::class)->profile();
    }

    public function test_local_rest_and_rpc_discovery_match_for_both_profiles_and_read_scopes(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $catalog = ToolRegistry::all();
        foreach (['full', 'essential'] as $profile) {
            config(['cms.admin_profile' => $profile]);
            $this->assertEquals($catalog, ToolRegistry::all());
            Artisan::call('cms:capabilities');
            $local = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($profile, $local['admin_profile']);
            foreach (['read-only', 'draft-only', 'full'] as $scope) {
                $this->withToken(McpApiKey::issue($actor, 'Discovery', $scope, null));
                $this->getJson('/mcp/capabilities')->assertOk()->assertExactJson(['data' => $local]);
                $rpc = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'cms_capabilities', 'arguments' => []]])->assertOk()->assertJsonMissingPath('error');
                $this->assertSame(['data' => $local], json_decode($rpc->json('result.content.0.text'), true));
            }
        }
    }

    public function test_essential_has_no_editor_routes_links_or_assets_and_keeps_public_data(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $content = ['meta' => ['title' => 'SEO preserved'], 'sections' => ['content' => ['heading' => 'Preserved heading', 'body' => '<p>Preserved body</p>', 'is_visible' => true]]];
        $page = Page::create(['title' => 'Profile fixture', 'slug' => 'profile-fixture', 'template_key' => 'default', 'status' => 'published', 'published_at' => now()->subMinute(), 'content_json' => $content]);
        $before = $page->fresh()->getRawOriginal();
        $this->actingAs($actor);
        $this->get('/admin/pages/'.$page->id.'/edit')->assertOk()->assertSee('editor-form');
        config(['cms.admin_profile' => 'essential']);
        $this->get('/admin/pages/'.$page->id.'/edit')->assertNotFound();
        $this->get('/admin/pages/create')->assertNotFound();
        $this->post('/admin/pages', ['title' => 'Forbidden UI write'])->assertNotFound();
        $this->put('/admin/pages/'.$page->id, ['title' => 'Forbidden UI write'])->assertNotFound();
        $this->get('/admin/posts/create')->assertNotFound();
        $this->get('/admin/pages')->assertOk()->assertDontSee('/admin/pages/create')->assertDontSee('/admin/pages/'.$page->id.'/edit')->assertDontSee('editor-engine')->assertSee('/admin/editorial/pages/'.$page->id);
        $this->get('/profile-fixture')->assertOk()->assertSee('Preserved body')->assertDontSee('/admin/pages/create')->assertDontSee('/admin/pages/'.$page->id.'/edit');
        $this->assertSame($before, $page->fresh()->getRawOriginal());
        config(['cms.admin_profile' => 'full']);
        $this->assertTrue(app(AdminCapabilities::class)->liveEditor());
        $this->assertSame($before, $page->fresh()->getRawOriginal());
    }

    public function test_custom_fields_require_editors_only_in_full(): void
    {
        config(['cms.templates.default.sections' => ['content', 'profile_testimonials'], 'cms.templates.default.schema' => config('cms.templates.default.schema') + ['sections.profile_testimonials.items.*.quote' => ['type' => 'text', 'translatable' => true]]]);
        $this->assertSame(1, Artisan::call('cms:check-conventions'));
        $this->assertStringContainsString('section-editor-missing', Artisan::output());
        $this->assertFalse(app(AdminCapabilities::class)->discovery()['templates']['default']['sections']['profile_testimonials']['visual_editor_available']);
        config(['cms.admin_profile' => 'essential']);
        $this->assertSame(0, Artisan::call('cms:check-conventions'), Artisan::output());
        $this->assertFalse(app(AdminCapabilities::class)->discovery()['templates']['default']['sections']['profile_testimonials']['specialized_editor_required']);
    }

    public function test_shared_section_editor_detection_matches_existing_registry(): void
    {
        $this->assertSame('admin.pages.sections.home_cta', app(AdminCapabilities::class)->sectionEditor('cta'));
        $this->assertTrue(app(AdminCapabilities::class)->discovery()['templates']['home-alt']['sections']['cta']['visual_editor_available']);
    }
}
