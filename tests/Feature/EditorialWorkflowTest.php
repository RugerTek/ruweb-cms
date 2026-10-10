<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Editorial\EditorialHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EditorialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public static function types(): array
    {
        return [['pages'], ['posts']];
    }

    private function content(string $type)
    {
        return ($type === 'pages' ? Page::class : Post::class)::create(['title' => 'Original', 'slug' => 'original', 'status' => 'draft', 'template_key' => 'default']);
    }

    private function url(string $action, $model, array $extra = []): string
    {
        return route('admin.editorial.'.$action, array_merge([$model->getTable(), $model->id], $extra));
    }

    #[DataProvider('types')]
    public function test_full_versions_restore_as_draft_without_rewriting_ledger(string $type): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user);
        $model = $this->content($type);
        $history = app(EditorialHistory::class);
        $first = $history->query($model)->value('id');
        $model->update(['title' => 'Changed', 'status' => 'published', 'published_at' => now()]);
        $ledger = DB::table('content_revisions')->pluck('hash')->all();
        $this->post($this->url('restore', $model, [$first]))->assertRedirect();
        $this->assertSame('Original', $model->fresh()->title);
        $this->assertSame('draft', $model->fresh()->status);
        foreach ($ledger as $hash) {
            $this->assertDatabaseHas('content_revisions', ['hash' => $hash]);
        }
        $this->assertGreaterThan(1, $history->query($model)->count());
        $this->get($this->url('index', $model, ['version' => $first]))->assertOk()->assertSee(__('editorial.compare'));
    }

    #[DataProvider('types')]
    public function test_recovery_is_user_scoped_conflict_aware_and_never_publishes(string $type): void
    {
        $one = User::factory()->create(['role' => 'editor']);
        $two = User::factory()->create(['role' => 'editor']);
        $model = $this->content($type);
        $hash = app(EditorialHistory::class)->fingerprint($model);
        $this->actingAs($one)->postJson($this->url('draft', $model), ['payload' => [['title', 'Unsaved'], ['status', 'published'], ['_editor_lock', 'secret']], 'base_fingerprint' => $hash])->assertOk();
        $this->assertSame('Original', $model->fresh()->title);
        $this->assertSame('draft', $model->fresh()->status);
        $this->actingAs($two)->getJson($this->url('draft', $model))->assertJson(['draft' => null]);
        $model->update(['title' => 'Other editor']);
        $this->actingAs($one)->getJson($this->url('draft', $model))->assertJson(['draft' => [['title', 'Unsaved']], 'conflict' => true]);
        DB::table('editorial_drafts')->update(['expires_at' => now()->subSecond()]);
        $this->getJson($this->url('draft', $model))->assertJson(['draft' => null]);
    }

    #[DataProvider('types')]
    public function test_editor_stages_live_changes_and_only_assigned_reviewer_applies_exact_proposal(string $type): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $model = $this->content($type);
        $model->update(['status' => 'published', 'published_at' => now()]);
        $data = ['title' => 'Proposed', 'slug' => 'original', 'status' => 'published'];
        if ($type === 'pages') {
            $data['template_key'] = 'default';
        }
        $this->actingAs($editor)->putJson(route("admin.$type.update", $model), $data)->assertOk()->assertJson(['staged' => true]);
        $this->assertSame('Original', $model->fresh()->title);
        $this->post($this->url('review', $model), ['reviewer_id' => $admin->id, 'comment' => 'Please review'])->assertRedirect();
        $review = DB::table('editorial_reviews')->latest('id')->first();
        $this->post($this->url('decide', $model, [$review->id]), ['state' => 'approved'])->assertForbidden();
        $this->actingAs($other)->post($this->url('decide', $model, [$review->id]), ['state' => 'approved'])->assertForbidden();
        $this->actingAs($admin)->post($this->url('decide', $model, [$review->id]), ['state' => 'approved'])->assertRedirect();
        $this->assertSame('Proposed', $model->fresh()->title);
        $this->assertSame('published', $model->fresh()->status);
    }

    #[DataProvider('types')]
    public function test_shared_preview_expires_revokes_and_has_safe_headers(string $type): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $model = $this->content($type);
        $response = $this->actingAs($admin)->post($this->url('share', $model), ['hours' => 1])->assertRedirect();
        $url = session('previewUrl');
        $this->assertNotEmpty($url);
        auth()->logout();
        $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Security-Policy', "form-action 'none'; frame-ancestors 'none'");
        $link = DB::table('editorial_preview_links')->first();
        $this->actingAs($admin)->delete($this->url('revoke', $model, [$link->id]))->assertRedirect();
        $this->get($url)->assertNotFound();
    }

    #[DataProvider('types')]
    public function test_shared_publication_scope_excludes_future_and_expired_content(string $type): void
    {
        $model = $this->content($type);
        $model->update(['status' => 'published', 'published_at' => now()->addMinute()]);
        $this->assertFalse($model->fresh()->isPublished());
        $this->assertSame(0, $model->newQuery()->published()->count());
        $model->update(['published_at' => now()->subMinute(), 'expires_at' => now()->subSecond()]);
        $this->assertFalse($model->fresh()->isPublished());
        $this->assertSame(0, $model->newQuery()->published()->count());
        $model->update(['expires_at' => now()->addMinute()]);
        $this->assertTrue($model->fresh()->isPublished());
        $this->assertSame(1, $model->newQuery()->published()->count());
    }

    public function test_first_programmatic_update_captures_preexisting_content_and_restore_requires_publisher_for_live_rows(): void
    {
        $page = Page::create(['title' => 'Before upgrade', 'slug' => 'upgrade', 'template_key' => 'default', 'status' => 'draft']);
        DB::table('editorial_versions')->delete();
        $page->update(['title' => 'After upgrade']);
        $version = DB::table('editorial_versions')->oldest('id')->first();
        $this->assertSame('Before upgrade', json_decode($version->payload, true)['attributes']['title']);
        $page->update(['status' => 'published', 'published_at' => now()]);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor)->post($this->url('restore', $page, [$version->id]))->assertForbidden();
        $this->assertTrue($page->fresh()->isPublished());
    }

    public function test_stale_review_cannot_overwrite_newer_content(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $page = $this->content('pages');
        $this->actingAs($editor)->post($this->url('review', $page), ['reviewer_id' => $admin->id])->assertRedirect();
        $review = DB::table('editorial_reviews')->first();
        $page->update(['title' => 'Newer']);
        $this->actingAs($admin)->post($this->url('decide', $page, [$review->id]), ['state' => 'approved'])->assertStatus(409);
    }

    public function test_expiry_command_records_unpublication_and_prunes_recovery(): void
    {
        $page = $this->content('pages');
        $page->update(['status' => 'published', 'published_at' => now()->subHour(), 'expires_at' => now()->subMinute()]);
        $this->artisan('cms:expire-content')->assertSuccessful();
        $this->assertSame('draft', $page->fresh()->status);
    }

    public function test_translation_review_shows_changed_source_and_approves_only_selected_field(): void
    {
        $base = $this->content('pages');
        $base->update(['title' => 'Old source', 'content_json' => ['meta' => ['title' => 'Old source', 'description' => 'Old description']]]);
        $translation = $base->replicate(['slug']);
        $translation->locale = 'en';
        $translation->slug = 'translated';
        $translation->title = 'Translated';
        $translation->syncTranslationFromBase();
        $translation->save();
        $base->update(['title' => 'New source', 'content_json' => ['meta' => ['title' => 'New source', 'description' => 'New description']]]);
        $translation = $translation->fresh();
        $change = app(EditorialHistory::class)->sourceChange($translation, 'meta.title');
        $this->assertSame('Old source', $change['before']);
        $this->assertSame('New source', $change['after']);
        $admin = User::factory()->create(['role' => 'admin']);
        $view = $this->actingAs($admin)->get($this->url('index', $translation))->assertOk()->assertSee('Old source')->assertSee('New source');
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();
        $this->post($this->url('approve-field', $translation), ['field' => 'meta.title', 'base_hash' => $change['hash'], '_editor_lock' => ($view->viewData('editorLease')['token'] ?? null)])->assertRedirect();
        $this->assertNotContains('meta.title', $translation->fresh()->staleTranslatableFields());
        $this->assertContains('meta.description', $translation->fresh()->staleTranslatableFields());
    }

    #[DataProvider('types')]
    public function test_real_editor_bearer_cannot_publish_or_unpublish_live_content(string $type): void
    {
        $live = $this->content($type);
        $live->update(['status' => 'published', 'published_at' => now()]);
        $draft = ($type === 'pages' ? Page::class : Post::class)::create(['title' => 'Draft', 'slug' => 'draft', 'status' => 'draft', 'template_key' => 'default']);
        $editor = User::factory()->create(['role' => 'editor']);
        $this->withToken($editor->generateMcpApiKey());
        $this->postJson("/mcp/$type/{$draft->id}/publish")->assertForbidden();
        $this->patchJson("/mcp/$type/{$live->id}/draft", ['title' => 'Bypass'])->assertForbidden();
        $this->assertSame('published', $live->fresh()->status);
        $this->assertSame('Original', $live->fresh()->title);
        foreach ([[$draft, 'pages.publish', 'posts.publish'], [$live, 'pages.update_draft', 'posts.update_draft']] as [$model,$pageTool,$postTool]) {
            $response = $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $type === 'pages' ? $pageTool : $postTool, 'arguments' => ['id' => $model->id, 'title' => 'Bypass']]])->assertOk();
            $this->assertTrue($response->json('result.isError') === true || $response->json('error') !== null);
        }
    }

    public function test_staged_proposals_replace_repeaters_preserve_omitted_media_and_preview_exact_proposal(): void
    {
        $page = $this->content('pages');
        $page->update(['status' => 'published', 'published_at' => now(), 'content_json' => ['sections' => ['content' => ['heading' => 'Live heading'], 'features' => ['logos' => [['label' => 'A'], ['label' => 'B'], ['label' => 'C']]]]]]);
        $media = Media::create(['filename' => 'sample.png', 'original_filename' => 'sample.png', 'path' => 'media/sample.png', 'mime_type' => 'image/png', 'extension' => 'png', 'size' => 100, 'disk' => 'public']);
        $page->attachMedia($media->id, 'featured_image');
        $editor = User::factory()->create(['role' => 'editor']);
        $this->actingAs($editor);
        $history = app(EditorialHistory::class);
        $history->stage($page, ['content_json' => ['sections' => ['content' => ['heading' => 'Proposed heading'], 'features' => ['logos' => [['label' => 'C']]]]]]);
        $version = $history->query($page)->where('is_proposal', true)->latest('id')->first();
        $payload = json_decode($version->payload, true);
        $this->assertCount(1, $payload['attributes']['content_json']['sections']['features']['logos']);
        $this->assertSame($media->id, $payload['media'][0]['id']);
        $this->post($this->url('share', $page), ['hours' => 1])->assertRedirect();
        $url = session('previewUrl');
        auth()->logout();
        $this->get($url)->assertOk()->assertSee('Proposed heading');
        $this->assertSame('Live heading', $page->fresh()->content_json['sections']['content']['heading']);
        $history->stage($page, ['content_json' => ['sections' => ['features' => ['logos' => []]]]]);
        $payload = json_decode($history->query($page)->where('is_proposal', true)->latest('id')->value('payload'), true);
        $this->assertSame([], $payload['attributes']['content_json']['sections']['features']['logos']);
    }

    public function test_removed_base_fields_are_visible_and_individually_acknowledgeable(): void
    {
        $base = $this->content('pages');
        $base->update(['content_json' => ['meta' => ['title' => 'Removed source']]]);
        $translation = $base->replicate(['slug']);
        $translation->locale = 'en';
        $translation->slug = 'translation';
        $translation->syncTranslationFromBase();
        $translation->save();
        $base->update(['content_json' => ['meta' => []]]);
        $translation = $translation->fresh();
        $this->assertContains('meta.title', $translation->staleTranslatableFields());
        $change = app(EditorialHistory::class)->sourceChange($translation, 'meta.title');
        $this->assertSame('Removed source', $change['before']);
        $this->assertSame(__('editorial.removed'), $change['after']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post($this->url('approve-field', $translation), ['field' => 'meta.title', 'base_hash' => $change['hash']])->assertRedirect();
        $this->assertSame([],$translation->fresh()->staleTranslatableFields());
    }
}
