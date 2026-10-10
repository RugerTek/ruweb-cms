<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Models\Taxonomy;
use App\Models\User;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhaseFiveEditorialTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private User $other;

    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();
        Locale::create(['code' => 'es', 'name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->editor = User::factory()->create(['role' => 'editor']);
        $this->other = User::factory()->create(['role' => 'admin']);
        $this->keys = [
            'admin' => McpApiKey::issue($this->admin, 'Reviewer', 'full', now()->addDay()),
            'editor' => McpApiKey::issue($this->editor, 'Author', 'full', now()->addDay()),
            'other' => McpApiKey::issue($this->other, 'Unassigned', 'full', now()->addDay()),
            'read' => McpApiKey::issue($this->admin, 'Reader', 'read-only', now()->addDay()),
            'draft' => McpApiKey::issue($this->admin, 'Drafter', 'draft-only', now()->addDay()),
        ];
    }

    public static function profilesAndTypes(): array
    {
        return [['full', 'pages'], ['essential', 'pages'], ['full', 'posts'], ['essential', 'posts']];
    }

    private function entity(string $type)
    {
        $model = ($type === 'pages' ? Page::class : Post::class)::create([
            'title' => 'Published original', 'slug' => 'phase-five-'.$type, 'locale' => 'es',
            'template_key' => 'default', 'status' => 'published', 'published_at' => now()->subHour(),
            'content' => '<h1>Original public heading</h1><p>Original body</p>',
            'content_json' => ['sections' => ['content' => ['heading' => 'Original public heading', 'body' => '<p>Original body</p>']]],
        ]);
        $media = Media::create(['filename' => 'history.svg', 'original_filename' => 'history.svg', 'path' => 'media/history.svg', 'mime_type' => 'image/svg+xml', 'extension' => 'svg', 'size' => 10, 'disk' => 'public']);
        $model->attachMedia($media->id, 'featured_image');
        app(EditorialHistory::class)->capture($model->fresh());

        return $model->fresh();
    }

    private function rpc(string $name, array $arguments, string $key = 'admin'): array
    {
        $response = $this->withToken($this->keys[$key])->postJson('/mcp/rpc', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ])->assertOk();
        $this->assertNull($response->json('error'), $response->getContent());
        $this->assertFalse($response->json('result.isError'), $response->getContent());
        $payload = json_decode($response->json('result.content.0.text'), true);

        $data = $payload['data'];
        foreach (['version', 'review', 'comment', 'link'] as $field) {
            if (isset($data[$field])) {
                return $data[$field] + $data;
            }
        }

        return $data;
    }

    private function base($model): array
    {
        return ['type' => $model->getTable(), 'id' => $model->id, 'expected_revision' => app(EditorialHistory::class)->fingerprint($model->fresh())];
    }

    private function endpoint($model, string $action): string
    {
        return '/mcp/content/'.$model->getTable().'/'.$model->id.'/'.$action;
    }

    #[DataProvider('profilesAndTypes')]
    public function test_published_changes_are_pinned_reviewed_and_restorable_with_author_and_media(string $profile, string $type): void
    {
        config(['cms.admin_profile' => $profile]);
        $model = $this->entity($type);
        $original = app(EditorialHistory::class)->payload($model);
        $baseline = app(EditorialHistory::class)->query($model)->latest('id')->value('id');
        $changes = ['title' => 'Approved proposal'] + ($type === 'pages' ? ['content_json' => ['sections' => ['content' => ['heading' => 'Exact proposed heading']]]] : ['content' => '<h1>Exact proposed heading</h1>']);
        $args = $this->base($model) + ['operation_key' => 'proposal-one', 'changes' => $changes];
        $proposal = $this->rpc('content_propose', $args, 'editor');
        $this->assertSame($proposal['id'], $this->rpc('content_propose', $args, 'editor')['id']);
        $reordered = array_reverse($args, true);
        $reordered['changes'] = array_reverse($args['changes'], true);
        $this->assertSame($proposal['id'], $this->rpc('content_propose', $reordered, 'editor')['id']);
        $this->assertSame($original, app(EditorialHistory::class)->payload($model->fresh()));
        $diff = $this->rpc('content_diff', ['type' => $type, 'id' => $model->id, 'version_id' => $proposal['id']], 'read');
        $this->assertSame('Approved proposal', $diff['diff']['attributes.title']['after']);
        $this->assertDatabaseHas('editorial_versions', ['id' => $proposal['id'], 'user_id' => $this->editor->id, 'is_proposal' => true]);
        $preview = $this->rpc('content_preview', $this->base($model) + ['version_id' => $proposal['id'], 'hours' => 1], 'editor');
        $this->withoutHeader('Authorization')->get($preview['url'])->assertOk()->assertSee('Exact proposed heading')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('Cache-Control', 'no-store, private');
        $review = $this->rpc('content_review_request', $this->base($model) + ['version_id' => $proposal['id'], 'reviewer_id' => $this->admin->id, 'operation_key' => 'request-one'], 'editor');
        $this->rpc('content_review_comment', $this->base($model) + ['review_id' => $review['id'], 'body' => 'Exact proposal checked', 'operation_key' => 'comment-one'], 'editor');
        $this->withToken($this->keys['other'])->postJson($this->endpoint($model, 'review-decide'), $this->base($model) + ['review_id' => $review['id'], 'state' => 'approved', 'operation_key' => 'approve-one'])->assertForbidden();
        $this->withToken($this->keys['draft'])->postJson($this->endpoint($model, 'review-decide'), $this->base($model) + ['review_id' => $review['id'], 'state' => 'approved', 'operation_key' => 'approve-one'])->assertForbidden();
        $decision = $this->base($model) + ['review_id' => $review['id'], 'state' => 'approved', 'operation_key' => 'approve-one'];
        $this->rpc('content_review_decide', $decision);
        $this->rpc('content_review_decide', $decision);
        $this->assertSame('Approved proposal', $model->fresh()->title);
        $this->assertTrue($model->fresh()->isPublished());
        $this->assertSame($original['media'], app(EditorialHistory::class)->payload($model->fresh())['media']);
        $this->withoutHeader('Authorization')->get($preview['url'])->assertOk()->assertSee('Exact proposed heading');
        $this->rpc('content_restore', $this->base($model) + ['version_id' => $baseline, 'mode' => 'draft', 'operation_key' => 'restore-one']);
        $this->assertSame('Published original', $model->fresh()->title);
        $this->assertSame('draft', $model->fresh()->status);
        $this->assertSame($original['media'], app(EditorialHistory::class)->payload($model->fresh())['media']);
        $this->rpc('content_preview_revoke', $this->base($model) + ['link_id' => $preview['id'], 'operation_key' => 'revoke-one'], 'editor');
        $this->withoutHeader('Authorization')->get($preview['url'])->assertNotFound();
        $this->assertDatabaseHas('editorial_versions', ['id' => $baseline]);
    }

    #[DataProvider('profilesAndTypes')]
    public function test_agent_and_human_conflicts_and_cross_entity_versions_fail_without_loss(string $profile, string $type): void
    {
        config(['cms.admin_profile' => $profile]);
        $model = $this->entity($type);
        $args = $this->base($model) + ['operation_key' => 'conflict-proposal', 'changes' => ['title' => 'Stale proposed title']];
        $proposal = $this->rpc('content_propose', $args, 'editor');
        $review = $this->rpc('content_review_request', $this->base($model) + ['operation_key' => 'conflict-review', 'version_id' => $proposal['id'], 'reviewer_id' => $this->admin->id], 'editor');
        EditorialActor::run($this->admin, fn () => $model->update(['title' => 'Human changed base']));
        $current = app(EditorialHistory::class)->payload($model->fresh());
        $this->withToken($this->keys['admin'])->postJson($this->endpoint($model, 'review-decide'), $this->base($model) + ['review_id' => $review['id'], 'state' => 'approved', 'operation_key' => 'approve-one'])->assertConflict();
        $this->withToken($this->keys['editor'])->postJson($this->endpoint($model, 'propose'), array_replace($args, ['operation_key' => 'stale-retry']))->assertConflict();
        $other = ($type === 'pages' ? Page::class : Post::class)::create(['title' => 'Other record', 'slug' => 'other-record', 'status' => 'draft', 'template_key' => 'default']);
        $this->withToken($this->keys['admin'])->postJson($this->endpoint($other, 'restore'), $this->base($other) + ['version_id' => $proposal['id'], 'mode' => 'draft', 'operation_key' => 'foreign-version'])->assertNotFound();
        $this->assertSame($current, app(EditorialHistory::class)->payload($model->fresh()));
        $this->assertSame('Other record', $other->fresh()->title);
    }

    public function test_read_scope_lists_history_but_cannot_propose_and_previews_expire(): void
    {
        $model = $this->entity('pages');
        $this->withToken($this->keys['read'])->getJson($this->endpoint($model, 'versions').'?per_page=1')->assertOk();
        $this->withToken($this->keys['read'])->postJson($this->endpoint($model, 'propose'), $this->base($model) + ['operation_key' => 'reader-write', 'changes' => ['title' => 'Forbidden']])->assertForbidden();
        $preview = $this->rpc('content_preview', $this->base($model) + ['hours' => 1]);
        $this->travel(61)->minutes();
        $this->withoutHeader('Authorization')->get($preview['url'])->assertNotFound();
        $this->assertSame('Published original', $model->fresh()->title);
    }

    public function test_publication_dates_are_protected_and_expiry_withdraws_public_content(): void
    {
        $model = $this->entity('pages');
        $this->withToken($this->keys['editor'])->postJson($this->endpoint($model, 'propose'), $this->base($model) + ['changes' => ['expires_at' => now()->addHour()->toISOString()], 'operation_key' => 'lifecycle-bypass'])->assertUnprocessable();
        $expiry = now()->addMinutes(5);
        $this->rpc('content_publication', $this->base($model) + ['action' => 'expire', 'expires_at' => $expiry->toISOString()]);
        $this->withoutHeader('Authorization')->get('/phase-five-pages')->assertOk();
        $this->travelTo($expiry->copy()->addSecond());
        $this->withoutHeader('Authorization')->get('/phase-five-pages')->assertNotFound();
        $this->artisan('cms:expire-content')->assertSuccessful();
        $this->assertSame('draft', $model->fresh()->status);
    }

    public function test_large_unicode_version_and_comment_ranges_recover_exact_values_with_intact_fingerprints(): void
    {
        $model = $this->entity('pages');
        $text = str_repeat('😀á', 3000);
        $proposal = $this->rpc('content_propose', $this->base($model) + ['changes' => ['content_json' => ['sections' => ['content' => ['body' => $text]]]], 'operation_key' => 'large-proposal'], 'editor');
        $full = $this->rpc('content_version', ['type' => 'pages', 'id' => $model->id, 'version_id' => $proposal['id']], 'read');
        $this->assertSame($proposal['fingerprint'], $full['fingerprint']);
        $recovered = '';
        for ($offset = 0; $offset < mb_strlen($text); $offset += 2048) {
            $range = $this->rpc('content_version', ['type' => 'pages', 'id' => $model->id, 'version_id' => $proposal['id'], 'field' => 'attributes.content_json.sections.content.body', 'offset' => $offset, 'length' => 2048], 'read');
            $this->assertSame($proposal['fingerprint'], $range['fingerprint']);
            $recovered .= $range['value'];
        }
        $this->assertSame($text, $recovered);
        $review = $this->rpc('content_review_request', $this->base($model) + ['version_id' => $proposal['id'], 'reviewer_id' => $this->admin->id, 'operation_key' => 'large-request'], 'editor');
        $comment = $this->rpc('content_review_comment', $this->base($model) + ['review_id' => $review['id'], 'body' => $text, 'operation_key' => 'large-comment'], 'editor');
        $recovered = '';
        for ($offset = 0; $offset < mb_strlen($text); $offset += 2048) {
            $range = $this->rpc('content_reviews', ['type' => 'pages', 'id' => $model->id, 'review_id' => $review['id'], 'comment_id' => $comment['id'], 'offset' => $offset, 'length' => 2048], 'read');
            $this->assertSame($this->editor->id, $range['user_id']);
            $recovered .= $range['value'];
        }
        $this->assertSame($text, $recovered);
    }

    public function test_post_proposal_replaces_categories_preserves_tags_and_restores_historical_associations(): void
    {
        $post = $this->entity('posts');
        $old = Taxonomy::create(['name' => 'Old', 'slug' => 'old', 'type' => 'category', 'locale' => 'es']);
        $new = Taxonomy::create(['name' => 'New', 'slug' => 'new', 'type' => 'category', 'locale' => 'es']);
        $tag = Taxonomy::create(['name' => 'Tag', 'slug' => 'tag', 'type' => 'tag', 'locale' => 'es']);
        $foreign = Taxonomy::create(['name' => 'English', 'slug' => 'english', 'type' => 'category', 'locale' => 'en']);
        $post->taxonomies()->sync([$old->id, $tag->id]);
        app(EditorialHistory::class)->capture($post->fresh());
        $baseline = app(EditorialHistory::class)->query($post)->latest('id')->value('id');
        $this->withToken($this->keys['editor'])->postJson($this->endpoint($post, 'propose'), $this->base($post) + ['changes' => ['categories' => [$foreign->id]], 'operation_key' => 'foreign-category'])->assertUnprocessable();
        $proposal = $this->rpc('content_propose', $this->base($post) + ['changes' => ['categories' => [$new->id]], 'operation_key' => 'replace-category'], 'editor');
        $pinned = $this->rpc('content_version', ['type' => 'posts', 'id' => $post->id, 'version_id' => $proposal['id']], 'read');
        $this->assertEqualsCanonicalizing([$new->id, $tag->id], $pinned['payload']['taxonomies']);
        $review = $this->rpc('content_review_request', $this->base($post) + ['version_id' => $proposal['id'], 'reviewer_id' => $this->admin->id, 'operation_key' => 'category-review'], 'editor');
        $this->assertSame($this->editor->id, $review['requested_by']);
        $comment = $this->rpc('content_review_comment', $this->base($post) + ['review_id' => $review['id'], 'body' => 'Category and tag checked', 'operation_key' => 'category-comment'], 'editor');
        $this->assertSame($this->editor->id, $comment['user_id']);
        $this->rpc('content_review_decide', $this->base($post) + ['review_id' => $review['id'], 'state' => 'approved', 'operation_key' => 'category-approve']);
        $this->assertEqualsCanonicalizing([$new->id, $tag->id], $post->fresh()->taxonomies()->pluck('taxonomies.id')->all());
        $this->assertSame($this->admin->id, $post->fresh()->updated_by);
        $this->assertSame(['es'], $post->fresh()->taxonomies()->pluck('locale')->unique()->values()->all());
        $this->assertSame($pinned['payload']['media'], app(EditorialHistory::class)->payload($post->fresh())['media']);
        $this->rpc('content_restore', $this->base($post) + ['version_id' => $baseline, 'mode' => 'draft', 'operation_key' => 'category-restore']);
        $this->assertEqualsCanonicalizing([$old->id, $tag->id], $post->fresh()->taxonomies()->pluck('taxonomies.id')->all());
        $this->assertSame('draft', $post->fresh()->status);
        $this->assertSame($pinned['payload']['media'], app(EditorialHistory::class)->payload($post->fresh())['media']);
        $this->assertSame(['es'], $post->fresh()->taxonomies()->pluck('locale')->unique()->values()->all());
        $this->assertSame($pinned['payload']['taxonomies'], $this->rpc('content_version', ['type' => 'posts', 'id' => $post->id, 'version_id' => $proposal['id']], 'read')['payload']['taxonomies']);
    }

    public function test_draft_scope_can_restore_as_proposal_preserving_publication_until_assigned_review(): void
    {
        $page = $this->entity('pages');
        $baseline = app(EditorialHistory::class)->query($page)->latest('id')->value('id');
        $page->update(['title' => 'New live title']);
        $publishedAt = $page->fresh()->published_at->toISOString();
        $restored = $this->rpc('content_restore', $this->base($page) + ['version_id' => $baseline, 'mode' => 'proposal', 'operation_key' => 'restore-as-proposal'], 'draft');
        $this->assertSame('New live title', $page->fresh()->title);
        $this->assertTrue($page->fresh()->isPublished());
        $review = $this->rpc('content_review_request', $this->base($page) + ['version_id' => $restored['id'], 'reviewer_id' => $this->admin->id, 'operation_key' => 'restore-proposal-review'], 'draft');
        $this->rpc('content_review_decide', $this->base($page) + ['review_id' => $review['id'], 'state' => 'approved', 'operation_key' => 'restore-proposal-approve']);
        $this->assertSame('Published original', $page->fresh()->title);
        $this->assertTrue($page->fresh()->isPublished());
        $this->assertSame($publishedAt, $page->fresh()->published_at->toISOString());
    }

    public function test_active_browser_lease_blocks_agent_changes_without_creating_receipts_or_versions(): void
    {
        $page = $this->entity('pages');
        DB::table('content_edit_locks')->insert(['content_type' => $page->getMorphClass(), 'content_id' => $page->id, 'user_id' => $this->admin->id, 'user_name' => $this->admin->name, 'session_hash' => hash('sha256', 'browser-session'), 'token' => 'browser-lease', 'expires_at' => now()->addMinute()]);
        $versions = app(EditorialHistory::class)->query($page)->count();
        $this->withToken($this->keys['editor'])->postJson($this->endpoint($page, 'propose'), $this->base($page) + ['changes' => ['title' => 'Blocked'], 'operation_key' => 'lease-blocked'])->assertStatus(423);
        $this->assertSame($versions, app(EditorialHistory::class)->query($page)->count());
        $this->assertDatabaseMissing('editorial_operation_receipts', ['operation_key' => 'lease-blocked']);
        $this->assertSame('Published original', $page->fresh()->title);
    }

    public function test_review_request_rejects_stale_proposal_base_from_agent_panel_and_restore_and_unknown_legacy_base(): void
    {
        foreach (['agent', 'panel', 'restore', 'legacy'] as $origin) {
            $page = EditorialActor::run($this->admin, fn () => Page::create(['title' => 'Base A', 'slug' => 'base-'.$origin, 'template_key' => 'default', 'status' => 'published', 'published_at' => now()->subHour()]));
            $baseline = app(EditorialHistory::class)->query($page)->latest('id')->value('id');
            if ($origin === 'panel') {
                EditorialActor::run($this->editor, fn () => app(EditorialHistory::class)->stage($page, ['title' => 'Panel proposal']));
                $proposal = ['id' => app(EditorialHistory::class)->query($page)->where('is_proposal', true)->latest('id')->value('id')];
            } elseif ($origin === 'restore') {
                $proposal = $this->rpc('content_restore', $this->base($page) + ['version_id' => $baseline, 'mode' => 'proposal', 'operation_key' => 'base-restore'], 'editor');
            } else {
                $proposal = $this->rpc('content_propose', $this->base($page) + ['changes' => ['title' => 'Agent proposal'], 'operation_key' => 'base-propose-'.$origin], 'editor');
            }
            if ($origin === 'legacy') {
                DB::table('editorial_versions')->where('id', $proposal['id'])->update(['base_fingerprint' => null]);
            } else {
                EditorialActor::run($this->admin, fn () => $page->update(['title' => 'Base B']));
            }
            $this->withToken($this->keys['editor'])->postJson($this->endpoint($page, 'review-request'), $this->base($page) + ['version_id' => $proposal['id'], 'reviewer_id' => $this->admin->id, 'operation_key' => 'stale-request-'.$origin])->assertConflict();
            $this->assertDatabaseMissing('editorial_reviews', ['version_id' => $proposal['id']]);
            $this->assertDatabaseMissing('editorial_operation_receipts', ['operation_key' => 'stale-request-'.$origin]);
            $this->assertSame($origin === 'legacy' ? 'Base A' : 'Base B', $page->fresh()->title);
        }
    }

    public function test_new_editorial_inputs_fail_with_field_diagnostics_without_mutation(): void
    {
        $model = $this->entity('pages');
        $version = app(EditorialHistory::class)->query($model)->latest('id')->value('id');
        $before = app(EditorialHistory::class)->payload($model);
        $this->withToken($this->keys['admin'])->postJson($this->endpoint($model, 'restore'), $this->base($model) + ['version_id' => $version, 'operation_key' => 'missing-mode'])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->withToken($this->keys['read'])->getJson($this->endpoint($model, 'version').'?version_id='.$version.'&field[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('field');
        $this->withToken($this->keys['admin'])->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'content_propose', 'arguments' => ['id' => $model->id]]])->assertOk()->assertJsonPath('error.code', -32602)->assertJsonStructure(['error' => ['data' => ['type']]]);
        $this->assertSame($before, app(EditorialHistory::class)->payload($model->fresh()));
        $this->withToken($this->keys['read'])->getJson('/mcp/content/pages/abc/version')->assertNotFound();
        $this->withToken($this->keys['admin'])->postJson('/mcp/content/pages/abc/restore', [])->assertNotFound();
        $this->assertDatabaseCount('editorial_operation_receipts', 0);
    }
}
