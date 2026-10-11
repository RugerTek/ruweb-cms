<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Media;
use App\Models\Menu;
use App\Models\Setting;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpRestBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_active' => true]);
        Locale::create(['code' => 'fr', 'name' => 'Français', 'is_active' => false]);
        $this->editor = User::factory()->create(['role' => 'editor']);
        $this->withToken($this->editor->generateMcpApiKey());
    }

    private function media(string $filename = 'example.svg'): Media
    {
        return Media::create([
            'filename' => $filename, 'original_filename' => $filename, 'path' => 'media/'.$filename,
            'mime_type' => 'image/svg+xml', 'extension' => 'svg', 'size' => 100, 'disk' => 'public',
            'alt' => 'Descripción base', 'title' => 'Título base',
        ]);
    }

    public function test_rest_get_returns_the_requested_media_record(): void
    {
        $other = $this->media('other.svg');
        $media = $this->media();
        $this->getJson('/mcp/media/'.$media->id)->assertOk()
            ->assertJsonPath('data.id', $media->id)->assertJsonPath('data.path', $media->path)
            ->assertJsonPath('data.url', $media->url())->assertJsonPath('data.alt', 'Descripción base');
        $this->assertDatabaseCount('media', 2);
        $this->assertNotSame($other->id, $media->id);
    }

    public function test_rest_patch_updates_only_the_requested_existing_media(): void
    {
        $other = $this->media('other.svg');
        $media = $this->media();
        $this->patchJson('/mcp/media/'.$media->id.'/metadata', ['alt' => 'Nuevo alt', 'title' => 'Nuevo título'])
            ->assertOk()->assertJsonPath('data.id', $media->id)->assertJsonPath('data.alt', 'Nuevo alt');
        $this->assertSame('Nuevo título', $media->fresh()->title);
        $this->assertSame('Descripción base', $other->fresh()->alt);
        $this->assertDatabaseCount('media', 2);
    }

    public function test_rest_locale_writes_translation_and_get_resolves_per_field_fallback(): void
    {
        $media = $this->media();
        $this->patchJson('/mcp/media/'.$media->id, ['locale' => 'en', 'alt' => 'English description'])
            ->assertOk()->assertJsonPath('data.id', $media->id)->assertJsonPath('data.alt', 'English description')
            ->assertJsonPath('data.title', 'Título base');
        $this->assertDatabaseHas('media_translations', ['media_id' => $media->id, 'locale' => 'en', 'alt' => 'English description']);
        $this->assertSame('Descripción base', $media->fresh()->alt);
        $this->getJson('/mcp/media/'.$media->id.'?locale=en')->assertOk()->assertJsonPath('data.alt', 'English description');
        $this->patchJson('/mcp/media/'.$media->id.'/metadata?locale=en', ['title' => 'English title'])
            ->assertOk()->assertJsonPath('data.alt', 'English description')->assertJsonPath('data.title', 'English title');
        $this->patchJson('/mcp/media/'.$media->id.'/metadata', ['locale' => 'en', 'alt' => '', 'title' => null])
            ->assertOk()->assertJsonPath('data.alt', 'Descripción base')->assertJsonPath('data.title', 'Título base');
        $this->assertDatabaseCount('media_translations', 0);
        $this->patchJson('/mcp/media/'.$media->id, ['locale' => 'es', 'alt' => 'Nueva base'])->assertOk();
        $this->assertSame('Nueva base', $media->fresh()->alt);
    }

    public function test_unknown_and_inactive_locales_and_invalid_text_do_not_update_the_asset(): void
    {
        $media = $this->media();
        foreach (['xx', 'fr', ''] as $locale) {
            $this->getJson('/mcp/media/'.$media->id.'?locale='.$locale)->assertUnprocessable();
            $this->patchJson('/mcp/media/'.$media->id.'/metadata', ['locale' => $locale, 'alt' => 'Invalid'])->assertUnprocessable();
        }
        $this->patchJson('/mcp/media/'.$media->id.'/metadata', ['locale' => 'en', 'alt' => str_repeat('a', 256)])->assertUnprocessable();
        $this->assertDatabaseCount('media_translations', 0);
        $this->assertSame('Descripción base', $media->fresh()->alt);
    }

    public function test_missing_records_are_404_and_authentication_runs_before_binding(): void
    {
        foreach (['media/999999', 'pages/999999', 'posts/999999', 'menus/999999'] as $path) {
            $this->getJson('/mcp/'.$path)->assertNotFound();
        }
        $this->patchJson('/mcp/media/999999/metadata', ['alt' => 'Missing'])->assertNotFound();
        $this->withToken('invalid-token');
        $this->getJson('/mcp/media/999999')->assertUnauthorized();
        $this->patchJson('/mcp/media/999999', ['alt' => 'Missing'])->assertUnauthorized();
        $this->withoutHeader('Authorization');
        $this->getJson('/mcp/pages/999999')->assertUnauthorized();
    }

    public function test_other_implicit_model_parameters_resolve_and_mutate_the_requested_records(): void
    {
        $page = Page::create(['title' => 'Page', 'slug' => 'page', 'template_key' => 'default', 'status' => 'draft', 'locale' => 'es']);
        $post = Post::create(['title' => 'Post', 'slug' => 'post', 'status' => 'draft', 'locale' => 'es']);
        $menu = Menu::create(['name' => 'Menu', 'slug' => 'menu', 'location' => 'header', 'locale' => 'es']);
        foreach (['pages' => $page, 'posts' => $post, 'menus' => $menu] as $path => $record) {
            $this->getJson('/mcp/'.$path.'/'.$record->id)->assertOk()->assertJsonPath('data.id', $record->id);
        }
        $this->patchJson('/mcp/pages/'.$page->id.'/draft', ['title' => 'Updated page'])->assertOk();
        $this->patchJson('/mcp/posts/'.$post->id.'/draft', ['title' => 'Updated post'])->assertOk();
        $this->patchJson('/mcp/menus/'.$menu->id, ['name' => 'Updated menu'])->assertOk();
        $this->assertSame('Updated page', $page->fresh()->title);
        $this->assertSame('Updated post', $post->fresh()->title);
        $this->assertSame('Updated menu', $menu->fresh()->name);
    }

    public function test_token_permissions_are_used_even_when_a_browser_admin_is_logged_in(): void
    {
        $menu = Menu::create(['name' => 'Menu', 'slug' => 'menu', 'location' => 'header', 'locale' => 'es']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        // The bearer token still belongs to an editor, who cannot delete menus.
        $this->deleteJson('/mcp/menus/'.$menu->id)->assertForbidden();
        $this->assertDatabaseHas('menus', ['id' => $menu->id]);
        auth()->logout();
        $this->withToken($admin->generateMcpApiKey());
        $this->deleteJson('/mcp/menus/'.$menu->id)->assertOk()->assertJsonPath('data.deleted.id', $menu->id);
        $this->assertDatabaseMissing('menus', ['id' => $menu->id]);
    }


    public function test_media_list_searches_translations_and_setting_key_stays_a_string(): void
    {
        $media = $this->media();
        $media->translations()->create(['locale' => 'en', 'alt' => 'English search', 'title' => 'English title']);
        $this->getJson('/mcp/media?locale=en&search=English')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $media->id)->assertJsonPath('data.0.title', 'English title');
        Setting::create(['key' => 'site_name', 'value' => 'MCP site', 'type' => 'string', 'group' => 'general']);
        $this->getJson('/mcp/settings/site_name')->assertOk()->assertJsonPath('data.key', 'site_name')->assertJsonPath('data.value', 'MCP site');
    }

    public function test_rpc_metadata_tool_accepts_locale_and_keeps_the_same_record(): void
    {
        $media = $this->media();
        $response = $this->postJson('/mcp/rpc', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'media_update_metadata', 'arguments' => ['media_id' => $media->id, 'locale' => 'en', 'alt' => 'RPC English']],
        ])->assertOk();
        $payload = json_decode($response->json('result.content.0.text'), true);
        $this->assertSame($media->id, $payload['data']['id']);
        $this->assertSame('RPC English', $payload['data']['alt']);
        $this->assertDatabaseHas('media_translations', ['media_id' => $media->id, 'locale' => 'en', 'alt' => 'RPC English']);
        $this->assertSame('Descripción base', $media->fresh()->alt);
        $read = $this->postJson('/mcp/rpc', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'media_get', 'arguments' => ['media_id' => $media->id, 'locale' => 'en']],
        ])->assertOk();
        $this->assertSame('RPC English', json_decode($read->json('result.content.0.text'), true)['data']['alt']);
    }
}
