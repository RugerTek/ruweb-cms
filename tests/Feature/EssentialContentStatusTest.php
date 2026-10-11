<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EssentialContentStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useAdminProfile('essential');
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_lists_preview_and_history_show_current_content_without_writes(): void
    {
        $page = Page::create(['title' => 'Operational draft', 'slug' => 'operational', 'template_key' => 'default', 'status' => 'draft', 'content_json' => ['sections' => ['content' => ['heading' => 'Saved heading', 'body' => '<p>Saved body</p>']]]]);
        $before = $page->fresh()->getRawOriginal();
        $versions = DB::table('editorial_versions')->count();
        $this->get('/admin/pages?search=operational&status=draft')->assertOk()->assertSee('Operational draft')->assertSee('data-content-list')->assertDontSee('/admin/pages/'.$page->id.'/edit')->assertDontSee('method="POST" action="http://localhost/admin/pages');
        $this->get('/admin/editorial/pages/'.$page->id)->assertOk()->assertSee('data-content-status')->assertSee(__('admin.back_to_content_list'))->assertDontSee(__('editorial.back'))->assertDontSee('admin/editorial/pages/'.$page->id.'/restore')->assertDontSee('request-comment');
        $this->get('/admin/content/pages/'.$page->id.'/preview')->assertOk()->assertSee('Saved body')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get('/operational')->assertNotFound();
        $this->assertSame($before, $page->fresh()->getRawOriginal());
        $this->assertSame($versions, DB::table('editorial_versions')->count());
        $this->assertDatabaseCount('editorial_preview_links', 0);
        $this->get('/admin/content/pages/'.$page->id.'?version=999999')->assertNotFound();
        $this->get('/admin/content/App%5CModels%5CPage')->assertNotFound();
    }

    public function test_states_match_public_visibility_and_all_locales_can_be_searched(): void
    {
        $fixtures = [
            'Draft state' => ['status' => 'draft'],
            'Live state' => ['status' => 'published', 'published_at' => now()->subMinute()],
            'Future state' => ['status' => 'published', 'published_at' => now()->addDay()],
            'Expired state' => ['status' => 'published', 'published_at' => now()->subDay(), 'expires_at' => now()->subMinute()],
        ];
        foreach ($fixtures as $title => $attributes) {
            Page::create(['title' => $title, 'slug' => str($title)->slug(), 'template_key' => 'default', 'locale' => 'en'] + $attributes);
        }
        foreach (['draft' => 'Draft state', 'published' => 'Live state', 'scheduled' => 'Future state', 'expired' => 'Expired state'] as $status => $title) {
            $response = $this->get('/admin/pages?status='.$status)->assertOk()->assertSee($title);
            foreach (array_keys($fixtures) as $other) {
                if ($other !== $title) {
                    $response->assertDontSee($other);
                }
            }
        }
        $this->get('/admin/pages?search=future-state')->assertOk()->assertSee('Future state');
        $this->get('/admin/pages?status=bogus')->assertRedirect()->assertSessionHasErrors('status');
    }

    public function test_registered_collection_policy_and_read_only_editorial_boundary(): void
    {
        config(['cms.collections.articles' => ['model' => Post::class, 'label' => 'admin.posts']]);
        $post = Post::create(['title' => 'Registered fixture', 'slug' => 'registered', 'status' => 'draft']);
        $this->get('/admin/content/articles')->assertOk()->assertSee('Registered fixture');
        $this->get('/admin/content/articles/'.$post->id)->assertOk();
        $this->get('/admin/content/articles/'.$post->id.'/preview')->assertOk();
        foreach ([['DELETE', '/admin/posts/'.$post->id], ['POST', '/admin/editorial/posts/'.$post->id.'/restore/1'], ['POST', '/admin/editorial/posts/'.$post->id.'/review'], ['POST', '/admin/editorial/posts/'.$post->id.'/share'], ['POST', '/admin/editorial/posts/'.$post->id.'/draft'], ['GET', '/admin/menus'], ['GET', '/admin/header'], ['GET', '/admin/footer'], ['GET', '/admin/taxonomies/category']] as [$method, $url]) {
            $this->call($method, $url)->assertNotFound();
        }
        $this->assertDatabaseHas('posts', ['id' => $post->id, 'title' => 'Registered fixture']);
        $this->actingAs(User::factory()->create(['role' => 'visitor']));
        $this->get('/admin/content/articles')->assertForbidden();
        $this->get('/admin/content/articles/'.$post->id.'/preview')->assertForbidden();
        auth()->logout();
        $this->get('/admin/content/articles/'.$post->id.'/preview')->assertNotFound();
    }
}
