<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Post;
use App\Models\User;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use App\Services\Posts\PostOperations;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PostOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_actor_controls_permissions_history_and_restores_session(): void
    {
        $session = User::factory()->create(['role' => 'editor']);
        $actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($session);
        $operations = app(PostOperations::class);
        $post = $operations->save($actor, ['title' => 'Shared', 'slug' => 'shared'], draftOnly: true);
        $post = $operations->publish($actor, $post);
        $this->assertSame('published', $post->status);
        $this->assertSame($actor->id, $post->created_by);
        $this->assertSame([$actor->id], DB::table('editorial_versions')->where('content_type', 'posts')->where('content_id', $post->id)->distinct()->pluck('user_id')->all());
        $this->assertSame([$actor->id], DB::table('content_revisions')->where('entity_type', Post::class)->where('entity_id', $post->id)->distinct()->pluck('user_id')->all());
        $this->assertSame($session->id, EditorialActor::current()->id);
        $this->expectException(AuthorizationException::class);
        $operations->unpublish($session, $post);
    }

    public function test_patch_preserves_scalar_and_seo_siblings_and_clears_explicit_null(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $operations = app(PostOperations::class);
        $post = $operations->save($actor, ['title' => 'Patch', 'slug' => 'patch', 'content' => '<p>Keep</p>', 'meta_json' => ['title' => 'SEO', 'description' => 'Old']], draftOnly: true);
        $revision = app(EditorialHistory::class)->fingerprint($post);
        $post = $operations->save($actor, ['meta_json' => ['description' => null], 'expected_revision' => $revision], $post, draftOnly: true);
        $this->assertSame(['title' => 'SEO'], $post->meta_json);
        $this->assertSame('<p>Keep</p>', $post->content);
        $post = $operations->save($actor, ['meta_json' => null], $post, draftOnly: true);
        $this->assertNull($post->meta_json);
        try {
            $operations->save($actor, ['title' => 'Lost', 'expected_revision' => $revision], $post, draftOnly: true);
            $this->fail('A stale revision must conflict.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Patch', $post->fresh()->title);
        }
    }

    public function test_locked_scope_rejects_persisted_publication_and_invalid_associations_roll_back(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $operations = app(PostOperations::class);
        $post = $operations->save($actor, ['title' => 'Draft', 'slug' => 'draft'], draftOnly: true);
        $counts = [DB::table('editorial_versions')->count(), DB::table('content_revisions')->count()];
        try {
            $operations->save($actor, ['title' => 'Invalid', 'categories' => [99999]], $post, draftOnly: true);
            $this->fail('Invalid taxonomy must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('categories.0', $exception->errors());
        }
        $this->assertSame('Draft', $post->fresh()->title);
        $this->assertSame($counts, [DB::table('editorial_versions')->count(), DB::table('content_revisions')->count()]);
        $operations->publish($actor, $post);
        $this->expectException(AuthorizationException::class);
        $operations->save($actor, ['title' => 'Bypass'], $post, draftOnly: true);
    }

    public function test_publication_schedule_and_translation_are_shared_operations(): void
    {
        foreach (['es', 'en'] as $locale) {
            Locale::create(['code' => $locale, 'name' => $locale, 'is_base' => $locale === 'es', 'is_active' => true]);
        }
        $actor = User::factory()->create(['role' => 'admin']);
        $operations = app(PostOperations::class);
        $post = $operations->save($actor, ['title' => 'Source', 'slug' => 'source'], draftOnly: true);
        $post = $operations->schedule($actor, $post, now()->addDay()->toIso8601String());
        $this->assertFalse($post->isPublished());
        $translation = $operations->translate($actor, $post, 'en');
        $this->assertSame('draft', $translation->status);
        $this->assertNull($translation->published_at);
        $this->assertSame($post->translation_group_id, $translation->translation_group_id);
        $this->assertSame($post->translatableFingerprint(), $translation->source_fingerprint);
        $this->assertSame($translation->id, $operations->translate($actor, $post, 'en')->id);
        $this->assertSame('draft', $operations->unpublish($actor, $post)->status);
    }

    public function test_editor_changes_to_live_post_stage_a_proposal(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $editor = User::factory()->create(['role' => 'editor']);
        $operations = app(PostOperations::class);
        $post = $operations->save($admin, ['title' => 'Live', 'slug' => 'live', 'status' => 'published']);
        $operations->save($editor, ['title' => 'Proposal', 'slug' => 'live', 'status' => 'published'], $post);
        $this->assertSame('Live', $post->fresh()->title);
        $row = DB::table('editorial_versions')->where('content_type', 'posts')->where('content_id', $post->id)->where('is_proposal', true)->first();
        $this->assertSame($editor->id, $row->user_id);
        $this->assertSame('Proposal', json_decode($row->payload, true)['attributes']['title']);
    }
}
