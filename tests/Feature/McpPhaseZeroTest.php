<?php

namespace Tests\Feature;

use App\Mcp\ToolRegistry;
use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Media;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpPhaseZeroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['es', 'en'] as $locale) {
            Locale::create(['code' => $locale, 'name' => $locale, 'is_base' => $locale === 'es', 'is_active' => true, 'is_public' => true]);
        }
        $this->withToken(McpApiKey::issue(User::factory()->create(['role' => 'admin']), 'Phase zero', 'full', null));
    }

    private function rpc(string $tool, array $args = []): TestResponse
    {
        return $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]);
    }

    private function rpcData(TestResponse $response): array
    {
        $response->assertOk()->assertJsonMissingPath('error');
        $this->assertNotSame(true, $response->json('result.isError'));

        return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_discovery_serializes_object_schemas_and_list_tools_are_callable(): void
    {
        $response = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk();
        $tools = json_decode($response->getContent())->result->tools;
        $this->assertCount(count(ToolRegistry::all()), $tools);
        foreach ($tools as $tool) {
            $this->assertIsObject($tool->inputSchema->properties, $tool->name);
        }
        $menu = Menu::create(['name' => 'Header', 'slug' => 'header', 'locale' => 'es']);
        Setting::set('site_name', 'Phase zero');
        $this->assertNotEmpty($this->rpcData($this->rpc('menus_list'))['data']);
        $this->assertNotEmpty($this->rpcData($this->rpc('settings_list')));
        $this->assertSame($menu->id, $this->rpcData($this->rpc('menus_get', ['menu_id' => $menu->id]))['data']['id']);
    }

    public function test_controller_rejections_are_tool_errors_and_do_not_mutate_settings(): void
    {
        Setting::create(['key' => 'phase0_readonly', 'value' => 'Original', 'type' => 'string', 'group' => 'general']);
        $before = Setting::where('key', 'phase0_readonly')->firstOrFail()->getAttributes();

        $this->patchJson('/mcp/settings/phase0_readonly', ['value' => 'Rejected'])->assertUnprocessable();
        $response = $this->rpc('settings_update', ['key' => 'phase0_readonly', 'value' => 'Rejected'])
            ->assertOk()->assertJsonMissingPath('error')->assertJsonPath('result.isError', true);
        $this->assertSame('Setting key is read-only in MCP.', json_decode($response->json('result.content.0.text'), true)['message']);
        $this->assertSame($before, Setting::where('key', 'phase0_readonly')->firstOrFail()->getAttributes());

        $this->getJson('/mcp/settings/phase0_missing')->assertNotFound();
        $this->rpc('settings_get', ['key' => 'phase0_missing'])->assertOk()->assertJsonPath('result.isError', true);
        // An allowed key may still be absent: preserve the controller's 404 as a tool error.
        $this->rpc('settings_update', ['key' => 'site_name', 'value' => 'Missing'])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertDatabaseMissing('settings', ['key' => 'site_name']);

        Setting::create(['key' => 'site_name', 'value' => 'Before', 'type' => 'string', 'group' => 'general']);
        $data = $this->rpcData($this->rpc('settings_update', ['key' => 'site_name', 'value' => 'After']));
        $this->assertSame('After', $data['data']['value']);
        $this->assertSame('After', Setting::get('site_name'));
    }

    public function test_secret_settings_are_redacted_in_both_transports(): void
    {
        Setting::create(['key' => 'phase0_secret', 'type' => 'password', 'group' => 'security']);
        Setting::set('phase0_secret', 'never-return-this-secret');
        foreach (['settings_list' => [], 'settings_get' => ['key' => 'phase0_secret']] as $tool => $args) {
            $data = $this->rpcData($this->rpc($tool, $args));
            $this->assertStringNotContainsString('never-return-this-secret', json_encode($data));
        }
        $this->getJson('/mcp/settings/phase0_secret')->assertOk()->assertJsonPath('data.value', null)->assertJsonPath('data.redacted', true);
    }

    public function test_partial_patch_preserves_siblings_replaces_lists_and_explicitly_removes_keys(): void
    {
        $content = ['meta' => ['title' => 'Keep title', 'description' => 'Before'], 'sections' => ['hero' => ['heading' => 'Keep hero', 'image_id' => 33], 'faq' => ['heading' => 'Keep FAQ', 'items' => [['question' => 'A'], ['question' => 'B']]]]];
        $page = Page::create(['title' => 'Draft', 'slug' => 'draft', 'template_key' => 'default', 'status' => 'draft', 'content_json' => $content]);
        $data = $this->rpcData($this->rpc('pages_update_draft', ['page_id' => $page->id, 'content_json' => ['meta' => ['description' => 'After']]]))['data'];
        $content['meta']['description'] = 'After';
        $this->assertSame($content, $data['content_json']);
        $this->patchJson('/mcp/pages/'.$page->id.'/draft', ['content_json' => ['meta' => ['description' => null], 'sections' => ['faq' => ['items' => [['question' => 'C']]]]]])->assertOk();
        unset($content['meta']['description']);
        $content['sections']['faq']['items'] = [['question' => 'C']];
        $this->assertSame($content, $page->fresh()->content_json);
        $this->rpcData($this->rpc('pages_update_draft', ['page_id' => $page->id, 'content_json' => ['sections' => ['faq' => ['items' => []]]]]));
        $this->assertSame([], $page->fresh()->content_json['sections']['faq']['items']);
        $before = $page->fresh()->content_json;
        $this->rpcData($this->rpc('pages_update_draft', ['page_id' => $page->id, 'content_json' => []]));
        $this->assertSame($before, $page->fresh()->content_json);
    }

    public function test_published_pages_and_posts_are_unchanged_for_admin_and_editor_on_both_transports(): void
    {
        foreach ([Page::class => 'pages', Post::class => 'posts'] as $model => $plural) {
            request()->setUserResolver(fn () => null);
            $entity = $model::create(['title' => 'Keep public', 'slug' => 'keep-public', 'status' => 'published', 'published_at' => now()->subDay(), ...($plural === 'pages' ? ['template_key' => 'default'] : [])]);
            $before = $entity->fresh()->getAttributes();
            foreach (['admin', 'editor'] as $role) {
                $this->withToken(McpApiKey::issue(User::factory()->create(['role' => $role]), $role, 'full', null));
                $this->rpc($plural.'_update_draft', [rtrim($plural, 's').'_id' => $entity->id, 'title' => 'Wrong'])->assertJsonPath('error.code', -32003);
                $this->patchJson('/mcp/'.$plural.'/'.$entity->id.'/draft', ['title' => 'Wrong'])->assertForbidden();
                $this->assertSame($before, $entity->fresh()->getAttributes());
            }
        }
        $this->get('/keep-public')->assertOk()->assertSee('Keep public');
    }

    public function test_locale_is_explicit_and_slug_uniqueness_is_scoped_to_locale(): void
    {
        foreach (['pages', 'posts'] as $plural) {
            $args = ['title' => 'Locale', 'slug' => 'same-slug', ...($plural === 'pages' ? ['template_key' => 'default'] : [])];
            $base = $this->rpcData($this->rpc($plural.'_create_draft', $args))['data'];
            $english = $this->rpcData($this->rpc($plural.'_create_draft', [...$args, 'locale' => 'en']))['data'];
            $this->assertSame('es', $base['locale']);
            $this->assertSame('en', $english['locale']);
            $this->assertNotEmpty($english['translation_group_id']);
            $this->rpc($plural.'_create_draft', [...$args, 'locale' => 'en'])->assertJsonPath('error.code', -32602);
            $this->rpc($plural.'_create_draft', [...$args, 'slug' => 'new', 'locale' => 'zz'])->assertJsonPath('error.code', -32602);
            $this->rpcData($this->rpc($plural.'_update_draft', [rtrim($plural, 's').'_id' => $english['id'], 'slug' => 'same-slug']));
            $model = $plural === 'pages' ? Page::class : Post::class;
            $this->assertSame(2, $model::count());
            $model::findOrFail($english['id'])->update(['translation_group_id' => $base['translation_group_id']]);
            $read = $this->rpcData($this->rpc($plural.'_get', [rtrim($plural, 's').'_id' => $english['id']]))['data'];
            $this->assertSame($base['translation_group_id'], $read['translation_group_id']);
        }
    }

    public function test_pagination_covers_more_than_one_hundred_records_without_duplicates(): void
    {
        foreach ([Page::class => 'pages', Post::class => 'posts', Media::class => 'media'] as $model => $plural) {
            $ids = [];
            for ($i = 0; $i < 105; $i++) {
                $attrs = $plural === 'media'
                    ? ['filename' => "$i.webp", 'original_filename' => "$i.webp", 'path' => "media/$i.webp", 'mime_type' => 'image/webp', 'extension' => 'webp', 'size' => 1, 'disk' => 'public']
                    : ['title' => "Item $i", 'slug' => "item-$i", 'status' => 'draft', ...($plural === 'pages' ? ['template_key' => 'default'] : [])];
                $ids[] = $model::create($attrs)->id;
            }
            $seen = [];
            for ($page = 1; $page <= 3; $page++) {
                $result = $this->rpcData($this->rpc($plural.'_list', ['per_page' => 50, 'page' => $page]));
                $this->assertSame(105, $result['meta']['total']);
                $this->assertSame($page, $result['meta']['current_page']);
                $this->assertSame(3, $result['meta']['last_page']);
                array_push($seen, ...array_column($result['data'], 'id'));
            }
            $this->assertSame(array_reverse($ids), $seen);
            $this->assertSame([], $this->rpcData($this->rpc($plural.'_list', ['per_page' => 50, 'page' => 4]))['data']);
            $this->getJson('/mcp/'.$plural.'?per_page=50&page=3')->assertOk()->assertJsonPath('meta.current_page', 3)->assertJsonCount(5, 'data');
            $this->rpc($plural.'_list', ['per_page' => 0])->assertJsonPath('error.code', -32602);
            $this->rpc($plural.'_list', ['page' => 0])->assertJsonPath('error.code', -32602);
        }
    }
}
