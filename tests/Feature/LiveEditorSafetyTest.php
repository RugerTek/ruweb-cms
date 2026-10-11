<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Content\ContentEditLock;
use App\Services\Snapshots\SnapshotService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class LiveEditorSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useAdminProfile('full');
    }

    public static function contentTypes(): array
    {
        return [['pages'], ['posts']];
    }

    private function content(string $type): Model
    {
        $data = ['title' => 'Original', 'slug' => 'original', 'status' => 'published', 'published_at' => now()];

        return $type === 'pages'
            ? Page::create($data + ['template_key' => 'default', 'content_json' => []])
            : Post::create($data + ['content' => '<p>Original</p>']);
    }

    private function lease(User $user, Model $content): array
    {
        $response = $this->actingAs($user)->get(route('admin.'.$content->getTable().'.edit', $content))->assertOk();
        $this->withCookie(config('session.cookie'), session()->getId())->withCredentials();

        return $response->viewData('editorLease');
    }

    private function saveData(Model $content, ?string $token = null): array
    {
        $data = ['title' => 'Changed', 'slug' => $content->slug, 'status' => $content->status];
        if ($content instanceof Page) {
            $data['template_key'] = 'default';
        }
        if ($token !== null) {
            $data['_editor_lock'] = $token;
        }

        return $data;
    }

    #[DataProvider('contentTypes')]
    public function test_another_user_cannot_open_save_or_delete_reserved_content(string $type): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $content = $this->content($type);
        $lease = $this->lease($owner, $content);

        $this->actingAs($other)->get(route("admin.$type.edit", $content))
            ->assertStatus(423)->assertSee($owner->name)->assertDontSee('id="editor-form"', false);
        $this->putJson(route("admin.$type.update", $content), $this->saveData($content))->assertStatus(423);
        $this->putJson(route("admin.$type.update", $content), $this->saveData($content, $lease['token']))->assertStatus(423);
        $this->deleteJson(route("admin.$type.destroy", $content))->assertStatus(423);
        $this->assertSame('Original', $content->fresh()->title);

        $this->actingAs($owner)->putJson(route("admin.$type.update", $content), $this->saveData($content, $lease['token']))
            ->assertOk()->assertJson(['saved' => true]);
        $this->assertSame('Changed', $content->fresh()->title);
    }

    #[DataProvider('contentTypes')]
    public function test_heartbeat_renews_and_only_the_owner_can_release(string $type): void
    {
        $owner = User::factory()->create(['role' => 'editor']);
        $other = User::factory()->create(['role' => 'editor']);
        $content = $this->content($type);
        $lease = $this->lease($owner, $content);
        $token = ['_editor_lock' => $lease['token']];
        $this->travel(90)->seconds();
        $this->postJson($lease['heartbeatUrl'], $token)->assertOk();
        $this->travel(60)->seconds();

        $this->actingAs($other)->postJson($lease['heartbeatUrl'], $token)->assertStatus(423);
        $this->postJson($lease['releaseUrl'], $token)->assertNoContent();
        $this->get(route("admin.$type.edit", $content))->assertStatus(423);
        $this->actingAs($owner)->postJson($lease['releaseUrl'], $token)->assertNoContent();
        $this->lease($other, $content);
    }

    #[DataProvider('contentTypes')]
    public function test_expired_editor_cannot_save_or_renew_and_a_new_user_can_enter(string $type): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $content = $this->content($type);
        $lease = $this->lease($owner, $content);
        $this->travel(ContentEditLock::TTL_SECONDS + 1)->seconds();
        $this->postJson($lease['heartbeatUrl'], ['_editor_lock' => $lease['token']])->assertStatus(423);
        $this->putJson(route("admin.$type.update", $content), $this->saveData($content, $lease['token']))->assertStatus(423);

        $newLease = $this->lease($other, $content);
        $this->actingAs($owner)->postJson($lease['releaseUrl'], ['_editor_lock' => $lease['token']])->assertNoContent();
        $this->putJson(route("admin.$type.update", $content), $this->saveData($content, $lease['token']))->assertStatus(423);
        $this->actingAs($other)->putJson(route("admin.$type.update", $content), $this->saveData($content, $newLease['token']))->assertOk();
    }

    #[DataProvider('contentTypes')]
    public function test_reopening_rotates_the_token_and_old_document_cannot_release_it(string $type): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $content = $this->content($type);
        $old = $this->lease($owner, $content);
        $new = $this->lease($owner, $content);
        $this->assertNotSame($old['token'], $new['token']);
        $this->postJson($old['releaseUrl'], ['_editor_lock' => $old['token']])->assertNoContent();
        $this->postJson($old['heartbeatUrl'], ['_editor_lock' => $old['token']])->assertStatus(423);
        $this->putJson(route("admin.$type.update", $content), $this->saveData($content, $old['token']))->assertStatus(423);
        $this->putJson(route("admin.$type.update", $content), $this->saveData($content, $new['token']))->assertOk();
    }

    #[DataProvider('contentTypes')]
    public function test_editor_cannot_be_framed_and_frame_request_does_not_acquire_a_lock(string $type): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $content = $this->content($type);
        $url = route("admin.$type.edit", $content);
        $this->actingAs($owner)->withHeader('Sec-Fetch-Dest', 'iframe')->get($url)->assertForbidden();
        $this->assertDatabaseCount('content_edit_locks', 0);
        $response = $this->withHeader('Sec-Fetch-Dest', 'document')->get($url)
            ->assertOk()->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
        $response->assertSee('name="_editor_lock"', false);
        $response->assertSee('editor-lock-notice', false);
        $response->assertDontSee('allow-forms', false);
    }

    #[DataProvider('contentTypes')]
    public function test_preview_omits_admin_bar_and_spacing_while_public_page_keeps_them(string $type): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $content = $this->content($type);
        $this->actingAs($owner)->post(route("admin.$type.preview", $content))
            ->assertOk()->assertDontSee('ruweb-admin-bar')->assertDontSee('pt-9');
        $this->get($content->url())->assertOk()->assertSee('ruweb-admin-bar')->assertSee('pt-9');
    }

    public function test_locks_are_independent_between_records_types_and_locales_and_survive_cache_flush(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $page = $this->content('pages');
        $post = $this->content('posts');
        $translation = $page->replicate();
        $translation->locale = 'en';
        $translation->slug = 'translated';
        $translation->save();
        $this->lease($owner, $page);
        $this->lease($other, $post);
        $this->lease($other, $translation);
        cache()->flush();
        $this->get(route('admin.pages.edit', $page))->assertStatus(423);
        $this->assertSame(3, DB::table('content_edit_locks')->count());
    }

    public function test_non_editor_submission_is_compatible_only_when_no_live_editor_owns_content(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $content = $this->content('pages');
        $this->actingAs($owner)->putJson(route('admin.pages.update', $content), $this->saveData($content))->assertOk();
        $lease = $this->lease($owner, $content);
        $this->putJson(route('admin.pages.update', $content), $this->saveData($content))->assertStatus(423);
        $this->postJson($lease['releaseUrl'], ['_editor_lock' => $lease['token']])->assertNoContent();
        $this->putJson(route('admin.pages.update', $content), $this->saveData($content))->assertOk();
    }

    public function test_same_user_in_another_browser_session_cannot_take_or_release_the_lock(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $content = $this->content('pages');
        $lease = $this->lease($owner, $content);
        $originalSession = session()->getId();
        $this->withCookie(config('session.cookie'), str_repeat('x', 40));
        $this->get(route('admin.pages.edit', $content))->assertStatus(423);
        $this->postJson($lease['heartbeatUrl'], ['_editor_lock' => $lease['token']])->assertStatus(423);
        $this->postJson($lease['releaseUrl'], ['_editor_lock' => $lease['token']])->assertNoContent();
        $this->assertDatabaseCount('content_edit_locks', 1);
        $this->withCookie(config('session.cookie'), $originalSession);
        $this->postJson($lease['heartbeatUrl'], ['_editor_lock' => $lease['token']])->assertOk();
    }

    public function test_snapshots_do_not_export_active_editor_tokens(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create(['role' => 'admin']);
        $this->lease($owner, $this->content('pages'));
        $archive = app(SnapshotService::class)->create('editor-locks');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archive));
        try {
            $manifest = json_decode($zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertContains('content_edit_locks', $manifest['database']['excluded_tables']);
            $this->assertNotContains('content_edit_locks', array_column($manifest['database']['tables'], 'name'));
            $this->assertFalse($zip->getFromName('database/content_edit_locks.jsonl'));
        } finally {
            $zip->close();
        }
    }
}
