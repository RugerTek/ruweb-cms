<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** An agent manages the site like the Live Editor: live edits and deletions through MCP. */
class McpLiveEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['es', 'en'] as $locale) {
            Locale::create(['code' => $locale, 'name' => $locale, 'is_base' => $locale === 'es', 'is_active' => true, 'is_public' => true]);
        }
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->as($this->admin);
    }

    private function as(User $user, string $scope = 'full'): void
    {
        $this->withToken(McpApiKey::issue($user, 'Live '.$scope, $scope, null));
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

    private function revision(string $type, int $id): string
    {
        return $this->rpcData($this->rpc($type.'_get', [rtrim($type, 's').'_id' => $id]))['data']['revision'];
    }

    private function publishedPage(string $slug = 'live', array $extra = []): Page
    {
        return Page::create(['title' => 'Live', 'slug' => $slug, 'template_key' => 'default', 'locale' => 'es', 'status' => 'published', 'published_at' => now()->subDay(),
            'content_json' => ['meta' => ['title' => 'SEO', 'description' => 'Desc'], 'sections' => ['content' => ['is_visible' => true, 'heading' => 'Old', 'body' => 'Body']]]] + $extra);
    }

    public function test_an_administrator_edits_published_content_live_with_patch_semantics(): void
    {
        $page = $this->publishedPage();
        $published = $page->fresh()->published_at;
        $data = $this->rpcData($this->rpc('pages_update', ['page_id' => $page->id, 'expected_revision' => $this->revision('pages', $page->id),
            'content_json' => ['meta' => ['description' => null], 'sections' => ['content' => ['heading' => 'New']]]]))['data'];

        $this->assertSame('published', $data['status']);
        $this->assertEquals($published, $page->fresh()->published_at);
        $this->assertSame(['title' => 'SEO'], $data['content_json']['meta']);
        $this->assertSame(['body' => 'Body', 'heading' => 'New', 'is_visible' => true], collect($data['content_json']['sections']['content'])->sortKeys()->all());

        $post = Post::create(['title' => 'Live post', 'slug' => 'live-post', 'locale' => 'es', 'status' => 'published', 'published_at' => now()->subDay(), 'meta_json' => ['title' => 'T', 'description' => 'D']]);
        $data = $this->rpcData($this->rpc('posts_update', ['post_id' => $post->id, 'expected_revision' => $this->revision('posts', $post->id), 'excerpt' => 'Edited', 'meta_json' => ['description' => null]]))['data'];
        $this->assertSame(['published', 'Edited', ['title' => 'T']], [$data['status'], $data['excerpt'], $data['meta_json']]);
    }

    public function test_live_edits_require_the_current_revision_and_cannot_change_publication(): void
    {
        $page = $this->publishedPage();
        $this->rpc('pages_update', ['page_id' => $page->id, 'title' => 'No revision'])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc('pages_update', ['page_id' => $page->id, 'expected_revision' => str_repeat('0', 64), 'title' => 'Stale'])->assertOk()->assertJsonPath('error.code', -32009);
        $this->rpcData($this->rpc('pages_update', ['page_id' => $page->id, 'expected_revision' => $this->revision('pages', $page->id), 'status' => 'draft', 'title' => 'Renamed']));
        $this->assertSame(['Renamed', 'published'], [$page->fresh()->title, $page->fresh()->status]);
    }

    public function test_editors_and_restricted_credentials_cannot_change_published_content_live(): void
    {
        $page = $this->publishedPage();
        $revision = $this->revision('pages', $page->id);
        $this->as(User::factory()->create(['role' => 'editor']));
        $this->rpc('pages_update', ['page_id' => $page->id, 'expected_revision' => $revision, 'title' => 'Editor'])->assertOk()
            ->assertJsonPath('error.code', -32003)->assertJsonPath('error.message', __('editorial.mcp_live_edit_admin_only'));
        $this->rpc('pages_delete', ['page_id' => $page->id, 'expected_revision' => $revision])->assertOk()->assertJsonPath('error.code', -32003);
        foreach (['draft-only', 'read-only'] as $scope) {
            $this->as($this->admin, $scope);
            foreach (['pages_update', 'pages_delete'] as $tool) {
                $this->rpc($tool, ['page_id' => $page->id, 'expected_revision' => $revision])->assertOk()->assertJsonPath('error.code', -32003);
            }
            $this->patchJson('/mcp/pages/'.$page->id, ['expected_revision' => $revision, 'title' => 'REST'])->assertForbidden();
            $this->deleteJson('/mcp/pages/'.$page->id, ['expected_revision' => $revision])->assertForbidden();
        }
        $this->assertSame('Live', $page->fresh()->title);
    }

    public function test_deleting_a_page_cleans_menu_links_and_reports_kept_translations(): void
    {
        $page = $this->publishedPage('about', ['translation_group_id' => 'group-about']);
        $english = Page::create(['title' => 'About', 'slug' => 'about', 'template_key' => 'default', 'locale' => 'en', 'status' => 'draft', 'translation_group_id' => 'group-about']);
        $menu = Menu::create(['name' => 'Header', 'slug' => 'header', 'locale' => 'es']);
        $parent = MenuItem::create(['menu_id' => $menu->id, 'label' => 'About', 'type' => 'page', 'linkable_type' => Page::class, 'linkable_id' => $page->id, 'target' => '_self', 'order' => 0]);
        $child = MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $parent->id, 'label' => 'Team', 'type' => 'custom_link', 'url' => '/team', 'target' => '_self', 'order' => 0]);

        $response = $this->rpcData($this->rpc('pages_delete', ['page_id' => $page->id, 'expected_revision' => $this->revision('pages', $page->id)]));

        $this->assertSame(['type' => 'pages', 'id' => $page->id, 'title' => 'Live', 'locale' => 'es', 'url' => '/about'], $response['data']['deleted']);
        $this->assertSame(['menu_items_removed', 'translations_kept'], array_column($response['warnings'], 'code'));
        $this->assertSame([['id' => $english->id, 'locale' => 'en']], $response['warnings'][1]['translations']);
        $this->assertNull(Page::find($page->id));
        $this->assertNull(MenuItem::find($parent->id));
        $this->assertNull($child->fresh()->parent_id);
        $this->assertNotNull($english->fresh());
        $this->rpc('pages_get', ['page_id' => $page->id])->assertOk()->assertJsonPath('error.message', 'Resource not found.');
    }

    public function test_the_homepage_cannot_be_deleted_and_stale_deletes_change_nothing(): void
    {
        $home = $this->publishedPage('inicio', ['translation_group_id' => 'group-home']);
        Setting::create(['key' => 'homepage_translation_group_id', 'value' => 'group-home', 'type' => 'select', 'group' => 'general']);
        $response = $this->rpc('pages_delete', ['page_id' => $home->id, 'expected_revision' => $this->revision('pages', $home->id)])->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertStringContainsString(__('editorial.homepage_delete_forbidden'), $response->json('error.message'));
        $this->rpc('pages_delete', ['page_id' => $home->id, 'expected_revision' => str_repeat('0', 64)])->assertOk()->assertJsonPath('error.code', -32009);
        $this->assertNotNull($home->fresh());
    }

    public function test_posts_are_deleted_over_both_transports(): void
    {
        $post = Post::create(['title' => 'Bye', 'slug' => 'bye', 'locale' => 'es', 'status' => 'published', 'published_at' => now()->subDay()]);
        $response = $this->rpcData($this->rpc('posts_delete', ['post_id' => $post->id, 'expected_revision' => $this->revision('posts', $post->id)]));
        $this->assertSame('posts', $response['data']['deleted']['type']);
        $this->assertNull(Post::find($post->id));

        $draft = Post::create(['title' => 'Rest', 'slug' => 'rest', 'locale' => 'es', 'status' => 'draft']);
        $this->deleteJson('/mcp/posts/'.$draft->id, ['expected_revision' => $this->revision('posts', $draft->id)])->assertOk()->assertJsonPath('data.deleted.id', $draft->id);
        $this->assertNull(Post::find($draft->id));
    }
}
