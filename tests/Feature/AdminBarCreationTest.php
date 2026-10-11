<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Support\AdminBar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminBarCreationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useAdminProfile('full');
    }

    private function page(): Page
    {
        return Page::create(['title' => 'Front', 'slug' => 'front', 'template_key' => 'default', 'status' => 'published', 'published_at' => now(), 'content_json' => []]);
    }

    public static function roles(): array
    {
        return [['admin'], ['editor']];
    }

    #[DataProvider('roles')]
    public function test_public_bar_offers_page_and_post_creation_with_current_url(string $role): void
    {
        $page = $this->page();
        $origin = url($page->url()).'?campaign=summer';
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user)->get($origin)->assertOk()
            ->assertSee('data-admin-bar-create', false)
            ->assertSee('Crear nuevo…')
            ->assertSee(route('admin.pages.create', ['return' => $origin]))
            ->assertSee(route('admin.posts.create', ['return' => $origin]));
        $links = AdminBar::createLinks($user, 'en', $origin);
        $this->assertSame(['Page', 'Post'], array_column($links, 'label'));
    }

    public function test_guest_and_live_preview_do_not_offer_creation(): void
    {
        $page = $this->page();
        $this->get($page->url())->assertOk()->assertDontSee('data-admin-bar-create', false);
        $this->actingAs(User::factory()->create(['role' => 'editor']))
            ->post(route('admin.pages.preview', $page), [])
            ->assertOk()->assertDontSee('data-admin-bar-create', false);
    }

    public function test_collection_registry_adds_future_types_and_omits_missing_routes(): void
    {
        Route::get('/admin/products/create', fn () => 'Product form')->name('admin.products.create');
        Route::getRoutes()->refreshNameLookups();
        Lang::get('admin.content_type_page', [], 'es');
        Lang::addLines(['admin.content_type_product' => 'Producto'], 'es');
        config(['cms.collections.products' => ['model' => Post::class, 'label' => 'admin.content_type_product', 'create_route' => 'admin.products.create'],
            'cms.collections.missing' => ['model' => Post::class, 'label' => 'admin.missing', 'create_route' => 'admin.missing.create']]);
        $user = User::factory()->create(['role' => 'editor']);
        $links = AdminBar::createLinks($user, 'es', url('/front'));
        $this->assertSame(['Página', 'Post', 'Producto'], array_column($links, 'label'));
        $this->assertSame(route('admin.products.create', ['return' => url('/front')]), $links[2]['href']);
    }

    public function test_collection_creation_policy_filters_the_menu(): void
    {
        Gate::policy(Post::class, AdminOnlyCreationPolicy::class);
        $editor = User::factory()->create(['role' => 'editor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertSame(['Página'], array_column(AdminBar::createLinks($editor, 'es', url('/front')), 'label'));
        $this->assertSame(['Página', 'Post'], array_column(AdminBar::createLinks($admin, 'es', url('/front')), 'label'));
        $this->actingAs($editor)->get(route('admin.posts.create'))->assertForbidden();
        $this->assertSame([], AdminBar::createLinks(User::factory()->create(['role' => 'visitor']), 'es', url('/front')));
    }

    public static function contentTypes(): array
    {
        return [['pages', Page::class], ['posts', Post::class]];
    }

    #[DataProvider('contentTypes')]
    public function test_create_save_and_editor_keep_the_public_origin(string $type, string $model): void
    {
        $user = User::factory()->create(['role' => 'editor']);
        $origin = url('/front?campaign=summer');
        $this->actingAs($user)->get(route("admin.$type.create", ['return' => $origin]))->assertOk()
            ->assertViewHas('editorBackHref', $origin)
            ->assertSee('name="return" value="'.$origin.'"', false);
        $data = ['title' => 'New content', 'slug' => 'new-content', 'status' => 'draft', 'return' => $origin];
        if ($type === 'pages') {
            $data['template_key'] = 'default';
        }
        $response = $this->post(route("admin.$type.store"), $data);
        $content = $model::where('slug', 'new-content')->firstOrFail();
        $edit = route("admin.$type.edit", [$content, 'return' => $origin]);
        $response->assertRedirect($edit);
        $this->get($edit)->assertOk()->assertViewHas('editorBackHref', $origin);
    }

    public function test_post_translation_retains_origin_and_rejects_external_return_targets(): void
    {
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_active' => true]);
        $user = User::factory()->create(['role' => 'editor']);
        $post = Post::create(['title' => 'Original', 'slug' => 'original', 'locale' => 'es', 'status' => 'draft']);
        $origin = url('/front?campaign=summer');
        $this->actingAs($user)->post(route('admin.posts.translate', [$post, 'en']), ['return' => $origin]);
        $translated = Post::where('locale', 'en')->firstOrFail();
        $this->get(route('admin.posts.edit', [$translated, 'return' => $origin]))->assertOk()
            ->assertViewHas('editorBackHref', $origin)
            ->assertSee(route('admin.posts.edit', [$post, 'return' => $origin]));
        foreach (['https://example.org/', '//example.org/', '/\\example.org/'] as $external) {
            $this->get(route('admin.posts.create', ['return' => $external]))->assertOk()
                ->assertViewHas('editorBackHref', route('admin.posts.index'));
        }
    }
}

class AdminOnlyCreationPolicy
{
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }
}
