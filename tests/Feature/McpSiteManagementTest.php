<?php

namespace Tests\Feature;

use App\Models\ContactSubmission;
use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\NotFoundHit;
use App\Models\Page;
use App\Models\Post;
use App\Models\Redirect as RedirectRule;
use App\Models\Setting;
use App\Models\Taxonomy;
use App\Models\User;
use App\Support\SettingDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Everything an agent needs to manage the site beyond pages and posts, in both profiles. */
class McpSiteManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['es', 'en'] as $locale) {
            Locale::create(['code' => $locale, 'name' => $locale, 'is_base' => $locale === 'es', 'is_active' => true, 'is_public' => true]);
        }
        foreach (SettingDefaults::all() as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }
        $this->withToken(McpApiKey::issue(User::factory()->create(['role' => 'admin']), 'Manage', 'full', null));
    }

    public static function profiles(): array
    {
        return [['full'], ['essential']];
    }

    private function rpc(string $tool, array $args = []): TestResponse
    {
        return $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]);
    }

    private function rpcData(TestResponse $response): array
    {
        $response->assertOk()->assertJsonMissingPath('error');
        $this->assertFalse($response->json('result.isError'), (string) $response->json('result.content.0.text'));

        return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    }

    #[DataProvider('profiles')]
    public function test_taxonomy_terms_are_managed_end_to_end(string $profile): void
    {
        $this->useAdminProfile($profile);
        $parent = $this->rpcData($this->rpc('taxonomies_create', ['type' => 'category', 'name' => 'Noticias', 'slug' => 'noticias', 'operation_key' => 'cat-1']))['data'];
        $this->assertSame($parent['id'], $this->rpcData($this->rpc('taxonomies_create', ['type' => 'category', 'name' => 'Noticias', 'slug' => 'noticias', 'operation_key' => 'cat-1']))['data']['id']);
        $child = $this->rpcData($this->rpc('taxonomies_create', ['type' => 'category', 'name' => 'Local', 'slug' => 'local', 'parent_id' => $parent['id']]))['data'];
        $this->rpc('taxonomies_create', ['type' => 'category', 'name' => 'Bad', 'slug' => 'Bad Slug'])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('taxonomies_create', ['type' => 'tag', 'name' => 'Tag', 'slug' => 'tag'])->assertOk()->assertJsonPath('error.code', -32602);

        $updated = $this->rpcData($this->rpc('taxonomies_update', ['taxonomy_id' => $parent['id'], 'expected_revision' => $parent['revision'], 'description' => 'Actualidad']))['data'];
        $this->assertSame(['Noticias', 'Actualidad'], [$updated['name'], $updated['description']]);
        $this->rpc('taxonomies_update', ['taxonomy_id' => $parent['id'], 'expected_revision' => $parent['revision'], 'name' => 'Stale'])->assertOk()->assertJsonPath('error.code', -32009);

        $english = $this->rpcData($this->rpc('taxonomies_translate', ['taxonomy_id' => $parent['id'], 'locale' => 'en']))['data'];
        $this->assertSame(['en', 'needs_review', $parent['id']], [$english['locale'], $english['translation_status'], $this->rpcData($this->rpc('taxonomies_translate', ['taxonomy_id' => $parent['id'], 'locale' => 'en']))['data']['id'] === $english['id'] ? $parent['id'] : null]);
        $this->assertCount(2, $this->rpcData($this->rpc('taxonomies_list', ['type' => 'category', 'locale' => 'es']))['data']);

        $post = Post::create(['title' => 'Uses', 'slug' => 'uses', 'locale' => 'es', 'status' => 'draft']);
        $post->syncTaxonomies([$parent['id']], 'category');
        $menu = Menu::create(['name' => 'Header', 'slug' => 'header', 'locale' => 'es']);
        MenuItem::create(['menu_id' => $menu->id, 'label' => 'News', 'type' => 'taxonomy', 'linkable_type' => Taxonomy::class, 'linkable_id' => $parent['id'], 'target' => '_self', 'order' => 0]);

        $deleted = $this->rpcData($this->rpc('taxonomies_delete', ['taxonomy_id' => $parent['id'], 'expected_revision' => $updated['revision']]));
        $this->assertSame(['menu_items_removed', 'translations_kept', 'children_moved_up', 'assignments_removed'], array_column($deleted['warnings'], 'code'));
        $this->assertNull(Taxonomy::find($parent['id']));
        $this->assertNull(Taxonomy::find($child['id'])->parent_id);
        $this->assertSame([], $post->fresh()->taxonomies()->pluck('taxonomies.id')->all());
    }

    #[DataProvider('profiles')]
    public function test_redirects_and_the_not_found_monitor_are_managed(string $profile): void
    {
        $this->useAdminProfile($profile);
        $rule = $this->rpcData($this->rpc('redirects_create', ['source_path' => 'old-page', 'target_url' => '/new-page', 'status_code' => 301, 'is_active' => true]))['data'];
        $this->assertSame(['/old-page', 0], [$rule['source_path'], $rule['hit_count']]);
        foreach (['javascript:alert(1)', 'data:text/html,x'] as $unsafe) {
            $this->rpc('redirects_create', ['source_path' => '/x', 'target_url' => $unsafe, 'status_code' => 301])->assertOk()->assertJsonPath('error.code', -32602);
        }
        $this->rpc('redirects_create', ['source_path' => '/new-page', 'target_url' => '/old-page', 'status_code' => 301, 'is_active' => true])->assertOk()->assertJsonPath('error.code', -32602);

        $patched = $this->rpcData($this->rpc('redirects_update', ['redirect_id' => $rule['id'], 'expected_revision' => $rule['revision'], 'is_active' => false]))['data'];
        $this->assertSame(['/new-page', false], [$patched['target_url'], $patched['is_active']]);
        RedirectRule::whereKey($rule['id'])->update(['hit_count' => 9]);
        $this->rpcData($this->rpc('redirects_delete', ['redirect_id' => $rule['id'], 'expected_revision' => $patched['revision']]));
        $this->assertNull(RedirectRule::find($rule['id']));

        $hits = collect(['/a', '/b'])->map(fn ($path) => NotFoundHit::create(['path' => $path, 'method' => 'GET', 'hit_count' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]));
        $this->assertCount(2, $this->rpcData($this->rpc('not_found_list'))['data']);
        $this->assertSame(1, $this->rpcData($this->rpc('not_found_forget', ['hit_id' => $hits[0]->id]))['data']['forgotten']);
        $this->assertSame(1, $this->rpcData($this->rpc('not_found_forget', ['all' => true]))['data']['forgotten']);
        $this->rpc('not_found_forget')->assertOk()->assertJsonPath('error.code', -32602);
    }

    public function test_settings_are_reset_to_their_starter_value(): void
    {
        $this->rpcData($this->rpc('settings_update_batch', ['settings' => ['site_name' => 'Cliente', 'footer_credit_enabled' => true]]));
        $this->rpcData($this->rpc('settings_update_batch', ['locale' => 'en', 'settings' => ['site_name' => 'Client']]));

        $this->rpcData($this->rpc('settings_reset', ['keys' => ['site_name'], 'locale' => 'en']));
        $this->assertSame(['Cliente', 'Cliente'], [Setting::get('site_name'), Setting::getLocalized('site_name', 'en')]);

        $reset = $this->rpcData($this->rpc('settings_reset', ['keys' => ['site_name', 'footer_credit_enabled']]))['data'];
        $this->assertSame(['Rüweb', false], [collect($reset)->firstWhere('key', 'site_name')['value'], collect($reset)->firstWhere('key', 'footer_credit_enabled')['value']]);
        $this->rpc('settings_reset', ['keys' => ['brevo_api_key']])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('settings_reset', ['keys' => ['homepage_translation_group_id']])->assertOk()->assertJsonPath('error.code', -32602);
    }

    public function test_rewriting_an_unchanged_setting_keeps_its_revision(): void
    {
        $before = $this->rpcData($this->rpc('settings_get', ['key' => 'footer_credit_enabled']))['data']['revision'];
        $this->travel(1)->minutes();
        $after = $this->rpcData($this->rpc('settings_update_batch', ['settings' => ['footer_credit_enabled' => false]]))['data'][0]['revision'];
        $this->assertSame($before, $after);
    }

    public function test_the_homepage_is_chosen_among_published_pages(): void
    {
        $page = Page::create(['title' => 'Nueva', 'slug' => 'nueva', 'template_key' => 'default', 'locale' => 'es', 'status' => 'published', 'published_at' => now()->subDay(), 'translation_group_id' => 'group-new']);
        Page::create(['title' => 'Borrador', 'slug' => 'borrador', 'template_key' => 'default', 'locale' => 'es', 'status' => 'draft', 'translation_group_id' => 'group-draft']);
        $this->rpc('settings_update', ['key' => 'homepage_translation_group_id', 'value' => 'group-draft'])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpcData($this->rpc('settings_update', ['key' => 'homepage_translation_group_id', 'value' => 'group-new']));
        $this->assertSame('/', $page->fresh()->url());
    }

    public function test_a_submission_is_deleted_with_an_audited_operation(): void
    {
        $record = ContactSubmission::create(['locale' => 'es', 'name' => 'Visitor', 'email' => 'visitor@example.test', 'subject' => 'Erase me', 'message' => 'Please delete my data']);
        $revision = $this->rpcData($this->rpc('submissions_get', ['form' => 'contact', 'id' => $record->id]))['data']['revision'];
        $this->assertSame(['deleted' => true, 'id' => $record->id], $this->rpcData($this->rpc('submissions_delete', ['form' => 'contact', 'id' => $record->id, 'expected_revision' => $revision, 'operation_key' => 'erase-1']))['data']);
        $this->assertNull(ContactSubmission::find($record->id));
    }

    public function test_menus_reject_executable_links_and_hide_unpublished_targets_publicly(): void
    {
        $menu = $this->rpcData($this->rpc('menus_create', ['name' => 'Header', 'slug' => 'header', 'locale' => 'es']))['data'];
        foreach (['javascript:alert(1)', 'data:text/html,x', 'vbscript:x', 'about'] as $unsafe) {
            $this->rpc('menus_sync_items', ['menu_id' => $menu['id'], 'expected_revision' => $menu['revision'], 'items' => [['label' => 'X', 'type' => 'custom_link', 'url' => $unsafe, 'target' => '_self', 'order' => 0]]])
                ->assertOk()->assertJsonPath('error.code', -32602);
        }
        $legacy = MenuItem::create(['menu_id' => $menu['id'], 'label' => 'Legacy', 'type' => 'custom_link', 'url' => 'javascript:alert(1)', 'target' => '_self', 'order' => 9]);
        $this->assertSame('#', $legacy->resolveUrl());

        $draft = Page::create(['title' => 'Draft', 'slug' => 'draft', 'template_key' => 'default', 'locale' => 'es', 'status' => 'draft']);
        $live = Page::create(['title' => 'Live', 'slug' => 'live', 'template_key' => 'default', 'locale' => 'es', 'status' => 'published', 'published_at' => now()->subDay()]);
        $parent = MenuItem::create(['menu_id' => $menu['id'], 'label' => 'Draft', 'type' => 'page', 'linkable_type' => Page::class, 'linkable_id' => $draft->id, 'target' => '_self', 'order' => 0]);
        MenuItem::create(['menu_id' => $menu['id'], 'parent_id' => $parent->id, 'label' => 'Child', 'type' => 'custom_link', 'url' => '/child', 'target' => '_self', 'order' => 0]);
        MenuItem::create(['menu_id' => $menu['id'], 'label' => 'Live', 'type' => 'page', 'linkable_type' => Page::class, 'linkable_id' => $live->id, 'target' => '_self', 'order' => 1]);

        $tree = Menu::findOrFail($menu['id']);
        $this->assertSame(['Draft', 'Live', 'Legacy'], $tree->tree()->pluck('label')->all());
        $this->assertSame(['Live', 'Legacy'], $tree->tree(public: true)->pluck('label')->values()->all());
    }

    public function test_restricted_credentials_cannot_delete_or_reset(): void
    {
        $term = Taxonomy::create(['type' => 'category', 'locale' => 'es', 'name' => 'Keep', 'slug' => 'keep']);
        foreach (['draft-only', 'read-only'] as $scope) {
            $this->withToken(McpApiKey::issue(User::factory()->create(['role' => 'admin']), $scope, $scope, null));
            $this->rpcData($this->rpc('taxonomies_list', ['type' => 'category']));
            foreach (['taxonomies_delete' => ['taxonomy_id' => $term->id, 'expected_revision' => str_repeat('0', 64)], 'settings_reset' => ['keys' => ['site_name']],
                'redirects_create' => ['source_path' => '/a', 'target_url' => '/b', 'status_code' => 301], 'not_found_forget' => ['all' => true]] as $tool => $args) {
                $this->rpc($tool, $args)->assertOk()->assertJsonPath('error.code', -32003);
            }
        }
        $this->assertNotNull($term->fresh());
    }
}
