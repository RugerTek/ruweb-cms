<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Models\SettingTranslation;
use App\Models\Taxonomy;
use App\Models\User;
use App\Services\Content\ContentSchemaRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultilanguagePublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_schema_resolves_page_and_type_based_entities(): void
    {
        $registry = app(ContentSchemaRegistry::class);

        $page = Page::create([
            'title' => 'Inicio',
            'slug' => 'inicio',
            'template_key' => 'home',
            'status' => 'draft',
        ]);

        $this->assertTrue($registry->isTranslatable($page));
        $this->assertContains('sections.hero.heading', $registry->pathsFor($page, 'translatable'));
    }

    public function test_setting_localized_cache_is_scoped_by_locale(): void
    {
        Setting::clearCache();
        $setting = Setting::updateOrCreate(
            ['key' => 'site_name'],
            ['value' => 'Global', 'type' => 'string', 'group' => 'general']
        );

        SettingTranslation::create(['setting_id' => $setting->id, 'locale' => 'es', 'value' => 'Español']);
        SettingTranslation::create(['setting_id' => $setting->id, 'locale' => 'en', 'value' => 'English']);

        $this->assertSame('Español', Setting::getLocalized('site_name', 'es'));
        $this->assertSame('English', Setting::getLocalized('site_name', 'en'));
    }

    public function test_localized_page_routes_and_fallbacks(): void
    {
        $this->seedLocales();

        $base = Page::create([
            'locale' => 'es',
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['heading' => 'Servicios']]],
            'status' => 'published',
            'published_at' => now(),
        ]);
        $base->source_fingerprint = $base->translatableFingerprint();
        $base->save();

        $translation = Page::create([
            'locale' => 'en',
            'translation_group_id' => $base->translation_group_id,
            'title' => 'Services',
            'slug' => 'services',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['heading' => 'Services']]],
            'status' => 'published',
            'published_at' => now(),
            'source_fingerprint' => $base->source_fingerprint,
        ]);

        $this->assertSame('/servicios', $base->url());
        $this->assertSame('/en/services', $translation->url());

        $this->get('/en/services')
            ->assertOk()
            ->assertSee('Services');

        $translation->update(['status' => 'draft']);

        $this->get('/en/services')
            ->assertRedirect('/servicios');
    }

    public function test_localized_blog_views_use_localized_routes_and_public_strings(): void
    {
        $this->seedLocales();

        $post = Post::create([
            'locale' => 'en',
            'title' => 'Launch',
            'slug' => 'launch',
            'excerpt' => 'Latest product updates.',
            'content' => 'Body',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->get('/en/blog')
            ->assertOk()
            ->assertSee('Latest articles and updates.')
            ->assertSee('href="'.$post->url().'"', false)
            ->assertSee('href="'.route('localized.home', ['locale' => 'en']).'"', false);

        $this->get('/en/blog/launch')
            ->assertOk()
            ->assertSee('Back to blog')
            ->assertSee('href="'.route('localized.blog.index', ['locale' => 'en']).'"', false);
    }

    public function test_fingerprint_ignores_slug_and_media_but_tracks_text(): void
    {
        $page = Page::create([
            'title' => 'Home',
            'slug' => 'home',
            'template_key' => 'default',
            'content_json' => [
                'sections' => [
                    'content' => [
                        'is_visible' => 1,
                        'heading' => 'Hello',
                        'body' => 'Body',
                        'image_id' => 10,
                    ],
                ],
            ],
            'status' => 'draft',
        ]);

        $first = $page->translatableFingerprint();

        $page->slug = 'changed';
        $page->content_json = [
            'sections' => [
                'content' => [
                    'is_visible' => 0,
                    'heading' => 'Hello',
                    'body' => 'Body',
                    'image_id' => 20,
                ],
            ],
        ];

        $this->assertSame($first, $page->translatableFingerprint());

        $page->content_json = [
            'sections' => [
                'content' => [
                    'heading' => 'Changed',
                    'body' => 'Body',
                ],
            ],
        ];

        $this->assertNotSame($first, $page->translatableFingerprint());
    }

    public function test_editorial_control_reports_missing_translation(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();

        $user = User::factory()->create(['role' => 'admin']);
        Page::create([
            'locale' => 'es',
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.editorial-control.index', ['tab' => 'translations']))
            ->assertOk()
            ->assertSee('Servicios')
            ->assertSee(__('admin.editorial_translation_state_missing'));
    }

    public function test_translation_save_clears_review_and_reaches_published_state(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);

        $base = Page::create([
            'locale' => 'es',
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['heading' => 'Servicios']]],
            'status' => 'published',
            'published_at' => now(),
        ]);
        $base->source_fingerprint = $base->translatableFingerprint();
        $base->save();

        $translation = Page::create([
            'locale' => 'en',
            'translation_group_id' => $base->translation_group_id,
            'title' => 'Services',
            'slug' => 'services',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['heading' => 'Services']]],
            'status' => 'published',
            'published_at' => now(),
            'translation_status' => 'needs_review',
            'source_fingerprint' => $base->source_fingerprint,
        ]);

        $this->assertSame('needs_review', $base->derivedTranslationState('en'));

        $this->actingAs($user)->put(route('admin.pages.update', $translation), [
            'title' => 'Services',
            'locale' => 'en',
            'slug' => 'services',
            'template_key' => 'default',
            'status' => 'published',
        ])->assertRedirect();

        $translation->refresh();

        $this->assertNull($translation->translation_status);
        $this->assertSame('published', $base->fresh()->derivedTranslationState('en'));
    }

    public function test_outdated_state_when_base_content_changes(): void
    {
        $this->seedLocales();

        $base = Page::create([
            'locale' => 'es',
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['heading' => 'Antes']]],
            'status' => 'published',
            'published_at' => now(),
        ]);
        $base->source_fingerprint = $base->translatableFingerprint();
        $base->save();

        Page::create([
            'locale' => 'en',
            'translation_group_id' => $base->translation_group_id,
            'title' => 'Services',
            'slug' => 'services',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['heading' => 'Before']]],
            'status' => 'published',
            'published_at' => now(),
            'source_fingerprint' => $base->source_fingerprint,
        ]);

        $this->assertSame('published', $base->derivedTranslationState('en'));

        $base->update(['content_json' => ['sections' => ['content' => ['heading' => 'Despues']]]]);

        $this->assertSame('outdated', $base->fresh()->derivedTranslationState('en'));
    }

    public function test_taxonomy_translation_helpers_work_without_publication_state(): void
    {
        $this->seedLocales();

        $taxonomy = Taxonomy::create([
            'locale' => 'es',
            'name' => 'Noticias',
            'slug' => 'noticias',
            'type' => 'category',
        ]);

        $this->assertCount(1, $taxonomy->availablePublishedTranslations());
        $this->assertSame('published', $taxonomy->derivedTranslationState('es'));
        $this->assertSame('missing', $taxonomy->derivedTranslationState('en'));
    }

    public function test_translate_action_creates_draft_without_inheriting_published_at(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);

        $base = Page::create([
            'locale' => 'es',
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['heading' => 'Servicios']]],
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('admin.pages.translate', [$base, 'en']))
            ->assertRedirect();

        $translation = Page::where('locale', 'en')
            ->where('translation_group_id', $base->translation_group_id)
            ->first();

        $this->assertNotNull($translation);
        $this->assertSame('draft', $translation->status);
        $this->assertNull($translation->published_at);
        $this->assertSame('needs_review', $translation->translation_status);
    }

    public function test_language_can_be_added_from_settings(): void
    {
        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->post(route('admin.languages.store'), ['code' => 'fr'])
            ->assertRedirect(route('admin.languages.index'));

        $fr = Locale::where('code', 'fr')->first();
        $this->assertNotNull($fr);
        $this->assertFalse($fr->is_base);
        $this->assertTrue($fr->is_active);
        $this->assertFalse($fr->is_public);

        $this->actingAs($user)
            ->post(route('admin.languages.store'), ['code' => 'xx'])
            ->assertSessionHasErrors('code');

        $this->actingAs($user)
            ->post(route('admin.languages.store'), ['code' => 'en'])
            ->assertSessionHasErrors('code');
    }

    public function test_pages_index_lists_one_row_per_translation_group(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);

        $base = Page::create([
            'locale' => 'es',
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'status' => 'published',
            'published_at' => now(),
        ]);

        Page::create([
            'locale' => 'en',
            'translation_group_id' => $base->translation_group_id,
            'title' => 'Services',
            'slug' => 'services',
            'template_key' => 'default',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.pages.index'))
            ->assertOk()
            ->assertSee('Servicios')
            ->assertDontSee('Services');
    }

    public function test_posts_index_lists_one_row_per_translation_group(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);

        $base = Post::create([
            'locale' => 'es',
            'title' => 'Noticia',
            'slug' => 'noticia',
            'content' => 'Contenido',
            'status' => 'published',
            'published_at' => now(),
        ]);

        Post::create([
            'locale' => 'en',
            'translation_group_id' => $base->translation_group_id,
            'title' => 'News',
            'slug' => 'news',
            'content' => 'Content',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.posts.index'))
            ->assertOk()
            ->assertSee('Noticia')
            ->assertDontSee('News');
    }

    public function test_post_translate_action_creates_draft_translation(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);

        $base = Post::create([
            'locale' => 'es',
            'title' => 'Noticia',
            'slug' => 'noticia',
            'content' => 'Contenido',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $baseCategory = Taxonomy::create([
            'locale' => 'es',
            'name' => 'Noticias',
            'slug' => 'noticias',
            'type' => 'category',
        ]);
        $translatedCategory = Taxonomy::create([
            'locale' => 'en',
            'translation_group_id' => $baseCategory->translation_group_id,
            'name' => 'News',
            'slug' => 'news',
            'type' => 'category',
        ]);
        $base->syncTaxonomies([$baseCategory->id], 'category');

        $media = Media::create([
            'filename' => 'cover.jpg',
            'original_filename' => 'cover.jpg',
            'path' => 'media/2026/05/cover.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'size' => 2048,
            'disk' => 'public',
            'uploaded_by' => $user->id,
        ]);
        $base->attachMedia($media->id, 'featured_image');

        $this->actingAs($user)
            ->post(route('admin.posts.translate', [$base, 'en']))
            ->assertRedirect();

        $translation = Post::where('locale', 'en')
            ->where('translation_group_id', $base->translation_group_id)
            ->first();

        $this->assertNotNull($translation);
        $this->assertSame('draft', $translation->status);
        $this->assertNull($translation->published_at);
        $this->assertSame('needs_review', $translation->translation_status);
        $this->assertSame($media->id, $translation->featuredImage()?->id);
        $this->assertSame([$translatedCategory->id], $translation->categories()->pluck('taxonomies.id')->all());

        $this->actingAs($user)
            ->get(route('admin.posts.edit', $translation))
            ->assertOk()
            ->assertSee(__('admin.language'));
    }

    public function test_taxonomy_translate_action_creates_translation(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);

        $base = Taxonomy::create([
            'locale' => 'es',
            'name' => 'Noticias',
            'slug' => 'noticias',
            'type' => 'category',
        ]);
        $child = Taxonomy::create([
            'locale' => 'es',
            'parent_id' => $base->id,
            'name' => 'Local',
            'slug' => 'local',
            'type' => 'category',
        ]);
        $translatedParent = Taxonomy::create([
            'locale' => 'en',
            'translation_group_id' => $base->translation_group_id,
            'name' => 'News',
            'slug' => 'news',
            'type' => 'category',
        ]);

        $this->actingAs($user)
            ->post(route('admin.taxonomies.translate', ['category', $child, 'en']))
            ->assertRedirect();

        $translation = Taxonomy::where('locale', 'en')
            ->where('translation_group_id', $child->translation_group_id)
            ->first();

        $this->assertNotNull($translation);
        $this->assertSame('needs_review', $translation->translation_status);
        $this->assertSame('category', $translation->type);
        $this->assertSame($translatedParent->id, $translation->parent_id);
    }

    public function test_editorial_control_offers_create_and_update_actions(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);

        Page::create([
            'locale' => 'es',
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'status' => 'published',
            'published_at' => now(),
        ]);

        $base = Page::create([
            'locale' => 'es',
            'title' => 'Equipo',
            'slug' => 'equipo',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['heading' => 'Antes']]],
            'status' => 'published',
            'published_at' => now(),
        ]);
        $base->source_fingerprint = $base->translatableFingerprint();
        $base->save();

        Page::create([
            'locale' => 'en',
            'translation_group_id' => $base->translation_group_id,
            'title' => 'Team',
            'slug' => 'team',
            'template_key' => 'default',
            'content_json' => ['sections' => ['content' => ['heading' => 'Before']]],
            'status' => 'published',
            'published_at' => now(),
            'source_fingerprint' => $base->source_fingerprint,
        ]);

        $base->update(['content_json' => ['sections' => ['content' => ['heading' => 'Despues']]]]);

        $this->actingAs($user)
            ->get(route('admin.editorial-control.index', ['tab' => 'translations']))
            ->assertOk()
            ->assertSee(__('admin.action_create'))
            ->assertSee(__('admin.action_update'));
    }

    public function test_editorial_control_lists_editorial_completeness_issues(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);

        Page::create([
            'locale' => 'es',
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'status' => 'published',
            'published_at' => now(),
        ]);

        Post::create([
            'locale' => 'es',
            'title' => 'Novedades',
            'slug' => 'novedades',
            'content' => 'Texto',
            'meta_json' => ['title' => 'SEO title'],
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('admin.editorial-control.index'))
            ->assertOk()
            ->assertSee(__('admin.editorial_completeness_title'))
            ->assertSee(__('admin.editorial_issue_featured_image_title'))
            ->assertSee(__('admin.editorial_issue_seo_title_title'))
            ->assertSee(__('admin.editorial_issue_seo_description_title'));
    }

    public function test_menus_index_shows_missing_language_actions(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        Locale::create(['code' => 'fr', 'name' => 'Français', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 2]);
        $user = User::factory()->create(['role' => 'admin']);

        Menu::create([
            'locale' => 'es',
            'name' => 'Header Menu',
            'slug' => 'header',
            'location' => 'header',
        ]);

        $this->actingAs($user)
            ->get(route('admin.menus.index'))
            ->assertOk()
            ->assertSee('Header Menu')
            ->assertSee('ES')
            ->assertSee('+EN')
            ->assertSee('+FR');
    }

    public function test_taxonomies_index_shows_language_actions(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        Locale::create(['code' => 'fr', 'name' => 'Français', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 2]);
        $user = User::factory()->create(['role' => 'admin']);

        $base = Taxonomy::create([
            'locale' => 'es',
            'name' => 'Noticias',
            'slug' => 'noticias',
            'type' => 'category',
        ]);

        Taxonomy::create([
            'locale' => 'en',
            'translation_group_id' => $base->translation_group_id,
            'name' => 'News',
            'slug' => 'news',
            'type' => 'category',
        ]);

        $this->actingAs($user)
            ->get(route('admin.taxonomies.index', 'category'))
            ->assertOk()
            ->assertSee('Noticias')
            ->assertSee('ES')
            ->assertSee('EN')
            ->assertSee('+FR');
    }

    public function test_menu_translate_action_copies_items_and_remaps_internal_links(): void
    {
        $this->useAdminProfile('full');

        $this->seedLocales();
        $user = User::factory()->create(['role' => 'admin']);

        $basePage = Page::create([
            'locale' => 'es',
            'title' => 'Servicios',
            'slug' => 'servicios',
            'template_key' => 'default',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $translatedPage = Page::create([
            'locale' => 'en',
            'translation_group_id' => $basePage->translation_group_id,
            'title' => 'Services',
            'slug' => 'services',
            'template_key' => 'default',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $baseCategory = Taxonomy::create([
            'locale' => 'es',
            'name' => 'Noticias',
            'slug' => 'noticias',
            'type' => 'category',
        ]);
        $translatedCategory = Taxonomy::create([
            'locale' => 'en',
            'translation_group_id' => $baseCategory->translation_group_id,
            'name' => 'News',
            'slug' => 'news',
            'type' => 'category',
        ]);

        $menu = Menu::create([
            'locale' => 'es',
            'name' => 'Header Menu',
            'slug' => 'header',
            'location' => 'header',
        ]);
        $pageItem = MenuItem::create([
            'menu_id' => $menu->id,
            'label' => 'Servicios',
            'type' => 'page',
            'linkable_type' => Page::class,
            'linkable_id' => $basePage->id,
            'target' => '_self',
            'order' => 0,
        ]);
        MenuItem::create([
            'menu_id' => $menu->id,
            'parent_id' => $pageItem->id,
            'label' => 'Noticias',
            'type' => 'taxonomy',
            'linkable_type' => Taxonomy::class,
            'linkable_id' => $baseCategory->id,
            'target' => '_self',
            'order' => 0,
        ]);

        $this->actingAs($user)
            ->post(route('admin.menus.translate', [$menu, 'en']))
            ->assertRedirect();

        $translation = Menu::where('locale', 'en')->where('slug', 'header')->first();
        $this->assertNotNull($translation);
        $this->assertSame('header', $translation->location);

        $items = $translation->items()->get();
        $rootItem = $items->firstWhere('type', 'page');
        $childItem = $items->firstWhere('type', 'taxonomy');

        $this->assertCount(2, $items);
        $this->assertSame($translatedPage->id, $rootItem->linkable_id);
        $this->assertSame('needs_review', $rootItem->translation_status);
        $this->assertSame($translatedCategory->id, $childItem->linkable_id);
        $this->assertSame($rootItem->id, $childItem->parent_id);
    }

    public function test_localized_menu_taxonomy_item_uses_localized_blog_route(): void
    {
        $this->seedLocales();

        $taxonomy = Taxonomy::create([
            'locale' => 'en',
            'name' => 'News',
            'slug' => 'news',
            'type' => 'category',
        ]);
        $menu = Menu::create([
            'locale' => 'en',
            'name' => 'Header Menu',
            'slug' => 'header',
            'location' => 'header',
        ]);
        $item = MenuItem::create([
            'menu_id' => $menu->id,
            'label' => 'News',
            'type' => 'taxonomy',
            'linkable_type' => Taxonomy::class,
            'linkable_id' => $taxonomy->id,
            'target' => '_self',
            'order' => 0,
        ]);

        $this->assertSame(url('/en/blog?category=news'), url($item->resolveUrl()));
    }

    private function seedLocales(): void
    {
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
    }
}
