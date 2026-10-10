<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\Page;
use App\Models\User;
use App\Services\Editorial\EditorialActor;
use App\Services\Pages\PageOperations;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PageOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_actor_controls_permissions_and_authorship_despite_another_session(): void
    {
        $session = User::factory()->create(['role' => 'editor']);
        $actor = User::factory()->create(['role' => 'admin']);
        $this->actingAs($session);
        $operations = app(PageOperations::class);
        $page = $operations->save($actor, ['title' => 'Shared', 'slug' => 'shared', 'template_key' => 'default'], draftOnly: true);
        $page = $operations->publish($actor, $page);
        $this->assertSame('published', $page->status);
        $this->assertSame($actor->id, $page->created_by);
        $this->assertSame($actor->id, $page->updated_by);
        $this->assertSame([$actor->id], DB::table('editorial_versions')->where('content_type', 'pages')->where('content_id', $page->id)->distinct()->pluck('user_id')->all());
        $this->assertSame([$actor->id], DB::table('content_revisions')->where('entity_type', Page::class)->where('entity_id', $page->id)->distinct()->pluck('user_id')->all());
        $this->assertSame($session->id, EditorialActor::current()->id);

        $this->actingAs($actor);
        try {
            $operations->publish($session, $page);
            $this->fail('Editor publication must be rejected.');
        } catch (AuthorizationException) {
            $this->assertSame($actor->id, EditorialActor::current()->id);
            $this->assertSame($actor->id, $page->fresh()->updated_by);
        }
    }

    public function test_invalid_input_rolls_back_all_content_history_and_restores_actor(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $counts = [Page::count(), DB::table('editorial_versions')->count(), DB::table('content_revisions')->count()];
        try {
            app(PageOperations::class)->save($actor, ['title' => 'Invalid', 'slug' => 'invalid', 'template_key' => 'default', 'featured_image' => 999999], draftOnly: true);
            $this->fail('Invalid media must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('featured_image', $exception->errors());
        }
        $this->assertSame($counts, [Page::count(), DB::table('editorial_versions')->count(), DB::table('content_revisions')->count()]);
        $this->assertNull(EditorialActor::current());
    }

    public function test_locked_save_rechecks_fingerprint_and_draft_status(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $operations = app(PageOperations::class);
        $page = $operations->save($actor, ['title' => 'Before', 'slug' => 'before', 'template_key' => 'default'], draftOnly: true);
        $stale = $page->replicate();
        $stale->id = $page->id;
        $operations->publish($actor, $page);
        $this->expectException(AuthorizationException::class);
        $operations->save($actor, ['title' => 'Must not change'], $stale, draftOnly: true);
    }

    public function test_partial_input_cannot_change_the_locale_used_to_validate_slug(): void
    {
        foreach (['es', 'en'] as $locale) {
            Locale::create(['code' => $locale, 'name' => $locale, 'is_base' => $locale === 'es', 'is_active' => true]);
        }
        $actor = User::factory()->create(['role' => 'admin']);
        $operations = app(PageOperations::class);
        $operations->save($actor, ['title' => 'Occupied', 'slug' => 'occupied', 'locale' => 'es', 'template_key' => 'default'], draftOnly: true);
        $page = $operations->save($actor, ['title' => 'Original', 'slug' => 'original', 'locale' => 'es', 'template_key' => 'default'], draftOnly: true);
        try {
            $operations->save($actor, ['slug' => 'occupied', 'locale' => 'en'], $page, draftOnly: true);
            $this->fail('Slug must be checked in the immutable persisted locale.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slug', $exception->errors());
            $this->assertSame('original', $page->fresh()->slug);
            $this->assertSame('es', $page->fresh()->locale);
        }
    }

    public function test_remote_null_patch_removes_a_normalized_browser_field(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $operations = app(PageOperations::class);
        $page = $operations->save($actor, ['title' => 'Patch', 'slug' => 'patch', 'template_key' => 'default', 'content_json' => ['sections' => ['features' => ['logos' => [1, 2]]]]], draftOnly: true);
        $page = $operations->save($actor, ['content_json' => ['sections' => ['features' => ['logos' => null]]]], $page, draftOnly: true);
        $this->assertArrayNotHasKey('logos', $page->content_json['sections']['features']);
    }

    public function test_stale_fingerprint_cannot_overwrite_a_saved_page(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $operations = app(PageOperations::class);
        $page = $operations->save($actor, ['title' => 'Keep', 'slug' => 'keep', 'template_key' => 'default'], draftOnly: true);
        try {
            $operations->save($actor, ['title' => 'Overwrite', 'slug' => 'keep', 'template_key' => 'default', 'status' => 'draft'], $page, base: str_repeat('0', 64));
            $this->fail('An obsolete fingerprint must conflict.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame('Keep', $page->fresh()->title);
        }
    }

    public function test_bearer_identity_controls_history_with_an_unrelated_session(): void
    {
        $session = User::factory()->create(['role' => 'editor']);
        $actor = User::factory()->create(['role' => 'admin']);
        $token = $actor->generateMcpApiKey();
        $this->actingAs($session);
        $response = $this->withToken($token)->postJson('/mcp/pages/drafts', ['title' => 'Bearer', 'slug' => 'bearer', 'template_key' => 'default'])->assertCreated();
        $id = $response->json('data.id');
        $this->postJson('/mcp/pages/'.$id.'/publish')->assertOk();
        $this->assertSame([$actor->id], DB::table('editorial_versions')->where('content_type', 'pages')->where('content_id', $id)->distinct()->pluck('user_id')->all());
    }
}
