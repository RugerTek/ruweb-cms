<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EditorNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useAdminProfile('full');
    }

    public function test_page_editor_returns_to_frontend_page_when_opened_from_public_bar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = Page::create([
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'content_json' => [],
            'status' => 'published',
            'published_at' => now(),
        ]);

        $returnUrl = url($page->url());

        $response = $this->actingAs($admin)->get(route('admin.pages.edit', [
            'page' => $page,
            'return' => $returnUrl,
        ]));

        $response->assertOk();
        $response->assertSee('href="' . e($returnUrl) . '"', false);
        $response->assertSee('title="Volver a la página anterior"', false);
    }

    public function test_post_editor_returns_to_frontend_post_when_opened_from_public_bar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $post = Post::create([
            'title' => 'Lanzamiento',
            'slug' => 'lanzamiento',
            'excerpt' => 'Resumen',
            'content' => 'Contenido',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $returnUrl = route('blog.show', $post->slug);

        $response = $this->actingAs($admin)->get(route('admin.posts.edit', [
            'post' => $post,
            'return' => $returnUrl,
        ]));

        $response->assertOk();
        $response->assertSee('href="' . e($returnUrl) . '"', false);
        $response->assertSee('title="Volver a la página anterior"', false);
    }

    public function test_blog_post_admin_bar_shows_only_post_edit_button(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $post = Post::create([
            'title' => 'Actualizacion',
            'slug' => 'actualizacion',
            'excerpt' => 'Resumen',
            'content' => 'Contenido',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('blog.show', $post->slug));

        $response->assertOk();
        $response->assertSee('Editar post');
        $response->assertDontSee('Editar página');
        $response->assertSee(e(route('admin.posts.edit', [
            'post' => $post,
            'return' => route('blog.show', $post->slug),
        ])), false);
    }

    public function test_editor_ignores_external_return_urls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $page = Page::create([
            'title' => 'Contacto',
            'slug' => 'contacto',
            'template_key' => 'default',
            'content_json' => [],
            'status' => 'draft',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.pages.edit', [
            'page' => $page,
            'return' => 'https://evil.example/phishing',
        ]));

        $response->assertOk();
        $response->assertSee('href="' . route('admin.pages.index') . '"', false);
        $response->assertDontSee('https://evil.example/phishing');
    }
}
