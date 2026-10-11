<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Support\EditorialTitle;
use App\Support\SectionRichText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LiveEditorUiTest extends TestCase
{
    use RefreshDatabase;

    private function page(string $template = 'home', array $sections = []): Page
    {
        return Page::create(['locale' => 'es', 'title' => 'Example', 'slug' => 'example-'.uniqid(), 'template_key' => $template, 'status' => 'published', 'published_at' => now(), 'content_json' => ['sections' => $sections]]);
    }

    public static function templates(): array
    {
        return [['default'], ['home'], ['home-alt'], ['contact']];
    }

    #[DataProvider('templates')]
    public function test_each_template_preserves_initial_values_and_exposes_shared_controls(string $template): void
    {
        $this->useAdminProfile('full');
        $section = config("cms.templates.$template.sections")[0];
        $page = $this->page($template, [$section => ['heading' => 'Original heading', 'body' => 'Original body', 'image_id' => null]]);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('admin.pages.edit', $page));
        $response->assertOk()->assertSee('role="separator"', false)->assertSee('data-title-input', false)->assertSee('data-rich-text=', false)->assertSee('value="Original heading"', false)->assertSee('Original body');
        $html = $response->getContent();
        $this->assertStringContainsString('name="_editor_lock"', substr($html, strpos($html, 'id="delete-form"')));
        $this->assertStringNotContainsString('section-accordion-header flex items-center justify-between px-4 py-3 cursor-pointer', $html);
    }

    public function test_section_presets_strip_unsupported_markup_on_write_and_render(): void
    {
        $page = $this->page('home', ['hero' => ['heading' => 'Hello', 'body' => '<p><strong>Bold</strong><ul><li>List</li></ul><img src="/x"><script>bad()</script><a href="javascript:bad()">Link</a></p>']]);
        $body = $page->fresh()->sections()['hero']['body'];
        $this->assertStringContainsString('<strong>Bold</strong>', $body);
        foreach (['<ul', '<li', '<img', '<script', 'javascript:', 'bad()'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $body);
        }
        $this->assertStringContainsString('<ul><li>List</li></ul>', (string) SectionRichText::render('<ul><li>List</li></ul>', 'section-body'));
        $this->assertSame("First<br />\nSecond", (string) SectionRichText::render("First\nSecond"));
        $this->assertSame(SectionRichText::comparisonValue('Original', 'short-description'), SectionRichText::comparisonValue('<p>Original</p>', 'short-description'));
    }

    public function test_emphasis_only_renders_the_allowed_token_and_escapes_title_html(): void
    {
        $html = (string) EditorialTitle::render('Hi [highlight]<img onerror="bad()">world[/highlight]');
        $this->assertStringContainsString('<span class="editorial-highlight">', $html);
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_cta_resolves_its_page_family_in_the_active_locale_and_falls_back(): void
    {
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true]);
        Locale::create(['code' => 'en', 'name' => 'English']);
        $target = $this->page('default');
        $translated = $target->replicate();
        $translated->locale = 'en';
        $translated->slug = 'translated-target';
        $translated->save();
        $home = $this->page('home', ['cta' => ['heading' => 'CTA', 'button_label' => 'Go', 'button_page' => $target->translation_group_id, 'button_new_tab' => true, 'button_url' => 'https://ignored.example']]);
        $home->locale = 'en';
        $html = view('templates.home', ['page' => $home])->render();
        $this->assertStringContainsString('href="/en/translated-target"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertNull($home->sections()['cta']['button_url']);
        $translated->delete();
        $this->assertStringContainsString('href="'.$target->url().'"', view('templates.home', ['page' => $home])->render());
    }

    public function test_invalid_destination_is_rejected_and_empty_repeaters_are_persisted(): void
    {
        $this->useAdminProfile('full');
        $page = $this->page('home', ['features' => ['logos' => [['label' => 'Existing']]], 'cta' => []]);
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = ['title' => $page->title, 'slug' => $page->slug, 'template_key' => 'home', 'status' => 'published', 'content_json' => ['sections' => ['features' => ['logos' => ''], 'cta' => ['button_url' => 'javascript:bad()']]]];
        $this->actingAs($admin)->putJson(route('admin.pages.update', $page), $payload)->assertUnprocessable()->assertJsonValidationErrors('content_json.sections.cta.button_url');
        $this->assertCount(1, $page->fresh()->sections()['features']['logos']);
        $payload['content_json']['sections']['cta']['button_url'] = '/contact';
        $this->putJson(route('admin.pages.update', $page), $payload)->assertOk();
        $this->assertSame([], $page->fresh()->sections()['features']['logos']);
    }

    public function test_owner_can_delete_using_the_token_from_the_actual_delete_form(): void
    {
        $this->useAdminProfile('full');
        $admin = User::factory()->create(['role' => 'admin']);
        foreach ([$this->page(), Post::create(['title' => 'Post', 'slug' => 'post', 'status' => 'draft'])] as $content) {
            $type = $content->getTable();
            $response = $this->actingAs($admin)->get(route("admin.$type.edit", $content));
            $html = $response->getContent();
            preg_match('/<form[^>]*id="delete-(?:form|post-form)"[^>]*>(.*?)<\/form>/s', $html, $form);
            $this->assertNotEmpty($form, 'No delete form');
            preg_match('/name="_editor_lock" value="([^"]+)"/', $form[1], $token);
            $this->assertNotEmpty($token, 'Delete form must carry its own lease');
            $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
            $this->delete(route("admin.$type.destroy", $content), ['_editor_lock' => $token[1]])->assertRedirect();
            $this->assertNull($content->fresh());
        }
    }

    public function test_entry_context_survives_locale_links_and_creation_of_a_translation(): void
    {
        $this->useAdminProfile('full');
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true]);
        Locale::create(['code' => 'en', 'name' => 'English', 'is_active' => true]);
        $page = $this->page();
        $admin = User::factory()->create(['role' => 'admin']);
        $origin = '/admin/pages?search=Example&status=published&page=2';
        $this->actingAs($admin)->get(route('admin.pages.edit', ['page' => $page, 'return' => $origin]))->assertOk()->assertViewHas('editorBackHref', url($origin))->assertSee('name="return"', false);
        $response = $this->post(route('admin.pages.translate', [$page, 'en']), ['return' => $origin]);
        $translation = Page::where('locale', 'en')->firstOrFail();
        $response->assertRedirect(route('admin.pages.edit', ['page' => $translation, 'return' => url($origin)]));
        $this->get(route('admin.pages.edit', ['page' => $translation, 'return' => 'https://evil.example']))->assertViewHas('editorBackHref', route('admin.pages.index'));
    }

    public function test_reordering_a_stale_item_cannot_silently_acknowledge_an_unchanged_label(): void
    {
        $this->useAdminProfile('full');
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true]);
        Locale::create(['code' => 'en', 'name' => 'English']);
        $base = $this->page('home', ['features' => ['logos' => [['label' => 'First'], ['label' => 'Second']]]]);
        $translation = $base->replicate();
        $translation->locale = 'en';
        $translation->slug = 'translation';
        $translation->syncTranslationFromBase();
        $translation->save();
        $base->update(['content_json' => ['sections' => ['features' => ['logos' => [['label' => 'Changed'], ['label' => 'Second']]]]]]);
        $payload = ['title' => $translation->title, 'slug' => $translation->slug, 'template_key' => 'home', 'status' => 'published', 'content_json' => ['sections' => ['features' => ['logos' => [['label' => 'Second'], ['label' => 'First']]]]], 'editor_field_origins' => [json_encode(['original' => 'content_json[sections][features][logos][0][label]', 'current' => 'content_json[sections][features][logos][1][label]'])]];
        $this->actingAs(User::factory()->create(['role' => 'admin']))->putJson(route('admin.pages.update', $translation), $payload)->assertUnprocessable()->assertJsonValidationErrors('content_json.sections.features.logos.1.label');
        $payload['acknowledged_fields'] = ['content_json[sections][features][logos][0][label]'];
        $this->putJson(route('admin.pages.update', $translation), $payload)->assertOk();
        $this->assertSame([], $translation->fresh()->staleTranslatableFields());
    }

    public function test_initial_rich_text_normalization_is_not_a_translation_review(): void
    {
        $this->useAdminProfile('full');
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true]);
        Locale::create(['code' => 'en', 'name' => 'English']);
        $base = $this->page('default', ['content' => ['body' => 'Original']]);
        $translation = $base->replicate();
        $translation->locale = 'en';
        $translation->slug = 'translation';
        $translation->syncTranslationFromBase();
        $translation->save();
        $base->update(['content_json' => ['sections' => ['content' => ['body' => 'Changed']]]]);
        $payload = ['title' => $translation->title, 'slug' => $translation->slug, 'template_key' => 'default', 'status' => 'published', 'content_json' => ['sections' => ['content' => ['body' => '<p>Original</p>']]]];
        $this->actingAs(User::factory()->create(['role' => 'admin']))->putJson(route('admin.pages.update', $translation), $payload)->assertUnprocessable()->assertJsonValidationErrors('content_json.sections.content.body');
    }

    public function test_normalizing_legacy_paragraphs_does_not_mark_translations_outdated(): void
    {
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true]);
        Locale::create(['code' => 'en', 'name' => 'English']);
        $base = $this->page('default', ['content' => ['body' => "Original\nSecond line"]]);
        $translation = $base->replicate();
        $translation->locale = 'en';
        $translation->slug = 'translated';
        $translation->syncTranslationFromBase();
        $translation->save();
        $base->update(['content_json' => ['sections' => ['content' => ['body' => '<p>Original<br>Second line</p>']]]]);
        $this->assertSame([], $translation->fresh()->staleTranslatableFields());
        $this->assertSame('published', $base->fresh()->derivedTranslationState('en'));
        $base->update(['content_json' => ['sections' => ['content' => ['body' => '<p><strong>Original</strong><br>Second line</p>']]]]);
        $this->assertSame(['sections.content.body'], $translation->fresh()->staleTranslatableFields());
    }
}
