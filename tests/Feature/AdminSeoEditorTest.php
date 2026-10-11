<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSeoEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useAdminProfile('full');
    }

    public function test_page_editor_uses_unified_seo_fields(): void
    {
        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);
        $page = Page::create([
            'locale' => 'es',
            'title' => 'Inicio',
            'slug' => 'inicio',
            'template_key' => 'default',
            'content_json' => [
                'meta' => [
                    'title' => 'Inicio SEO',
                    'description' => 'Descripcion SEO',
                ],
            ],
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get(route('admin.pages.edit', $page))
            ->assertOk()
            ->assertSee('SEO Title')
            ->assertSee('SEO Description')
            ->assertDontSee('OG Title')
            ->assertDontSee('OG Description');
    }

    public function test_post_editor_uses_unified_seo_fields(): void
    {
        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);
        $post = Post::create([
            'locale' => 'es',
            'title' => 'Novedades',
            'slug' => 'novedades',
            'meta_json' => [
                'title' => 'Novedades SEO',
                'description' => 'Descripcion SEO',
            ],
            'status' => 'draft',
        ]);

        $this->actingAs($user)
            ->get(route('admin.posts.edit', $post))
            ->assertOk()
            ->assertSee('SEO Title')
            ->assertSee('SEO Description')
            ->assertDontSee('OG Title')
            ->assertDontSee('OG Description');
    }

    private function seedLocales(): void
    {
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
    }
}
