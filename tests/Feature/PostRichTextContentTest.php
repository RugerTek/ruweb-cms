<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostRichTextContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_model_sanitizes_content_on_write(): void
    {
        $post = Post::create([
            'title' => 'Test',
            'slug' => 'test',
            'locale' => 'es',
            'content' => '<p>Safe</p><script>alert(1)</script>',
            'status' => 'draft',
        ]);

        $this->assertStringNotContainsString('script', $post->fresh()->content);
        $this->assertStringContainsString('Safe', $post->fresh()->content);
    }

    public function test_content_submitted_through_the_admin_form_is_sanitized(): void
    {
        $this->useAdminProfile('full');

        $this->seed(\Database\Seeders\LocaleSeeder::class);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('admin.posts.store'), [
            'title' => 'Payload',
            'slug' => 'payload',
            'locale' => 'es',
            'status' => 'published',
            'content' => '<h2>Title</h2><img src=x onerror="alert(1)"><a href="javascript:alert(2)">x</a>',
        ])->assertSessionHasNoErrors();

        $post = Post::where('slug', 'payload')->firstOrFail();

        $this->assertStringContainsString('<h2>Title</h2>', $post->content);
        $this->assertStringNotContainsString('onerror', $post->content);
        $this->assertStringNotContainsString('javascript:', $post->content);
    }

    public function test_the_public_page_renders_rich_text_as_markup(): void
    {
        $post = Post::create([
            'title' => 'Rendered',
            'slug' => 'rendered',
            'locale' => 'es',
            'content' => '<h2>Subtitle</h2><p>Body with <strong>bold</strong>.</p>',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->get($post->url());

        $response->assertOk();
        $response->assertSee('<h2>Subtitle</h2>', false);
        $response->assertSee('<strong>bold</strong>', false);
        // Not double-escaped into visible tags.
        $response->assertDontSee('&lt;h2&gt;', false);
    }

    public function test_legacy_plain_text_posts_keep_their_line_breaks(): void
    {
        $post = Post::create([
            'title' => 'Legacy',
            'slug' => 'legacy',
            'locale' => 'es',
            'content' => "First line\nSecond line",
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->get($post->url());

        $response->assertOk();
        $response->assertSee('<br />', false);
        $response->assertSee('First line', false);
    }

    public function test_a_script_in_stored_content_never_reaches_the_public_page(): void
    {
        $post = Post::create([
            'title' => 'Xss',
            'slug' => 'xss',
            'locale' => 'es',
            'status' => 'published',
            'published_at' => now(),
        ]);

        // Simulates content that bypassed the model, e.g. a direct database write.
        Post::withoutEvents(fn () => Post::where('id', $post->id)->update([
            'content' => '<p>Hi</p><script>alert(1)</script>',
        ]));

        $response = $this->get($post->fresh()->url());

        $response->assertOk();
        $response->assertSee('Hi', false);

        // Scope the check to the article body: the layout loads its own scripts.
        preg_match('/<article.*?<\/article>/s', $response->getContent(), $match);
        $this->assertNotEmpty($match, 'The article body was not rendered.');
        $this->assertStringNotContainsString('<script', $match[0]);
        $this->assertStringNotContainsString('alert(1)', $match[0]);
    }

    public function test_the_editor_form_marks_the_content_field_as_rich_text(): void
    {
        $this->useAdminProfile('full');

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.posts.create'));

        $response->assertOk();
        $response->assertSee('data-rich-text="post-body"', false);
    }
}
