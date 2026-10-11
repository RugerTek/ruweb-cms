<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class McpPhaseThreeTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $role = 'admin', string $scope = 'full'): string
    {
        return McpApiKey::issue(User::factory()->create(['role' => $role]), 'Phase three', $scope, null);
    }

    private function locales(): void
    {
        foreach (['es', 'en'] as $locale) {
            Locale::create(['code' => $locale, 'name' => $locale, 'is_base' => $locale === 'es', 'is_active' => true]);
        }
    }

    private function rpc(string $tool, array $arguments = []): TestResponse
    {
        return $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
    }

    public function test_schema_and_locale_discovery_are_available_to_restricted_credentials(): void
    {
        $this->locales();
        foreach (['read-only', 'draft-only'] as $scope) {
            $this->withToken($this->token('editor', $scope));
            $this->getJson('/mcp/schema?type=pages&template_key=home')->assertOk()->assertJsonStructure(['data' => ['schema', 'rules', 'example', 'locales']]);
            $this->getJson('/mcp/schema?type=posts')->assertOk()->assertJsonPath('data.type', 'posts');
            $this->getJson('/mcp/schema?type=pages&template_key=invented')->assertUnprocessable();
            $this->getJson('/mcp/locales')->assertOk()->assertJsonCount(2, 'data.installed');
            $this->rpc('cms_schema', ['type' => 'posts'])->assertOk()->assertJsonPath('result.isError', false);
            $this->rpc('locales_list')->assertOk()->assertJsonPath('result.isError', false);
            $this->rpc('locales_configure', ['locales' => ['en' => ['is_active' => false]]])->assertOk()->assertJsonPath('error.code', -32003);
            $this->postJson('/mcp/locales', ['locales' => ['en' => ['is_active' => false]]])->assertForbidden();
        }
        $this->assertTrue(Locale::where('code', 'en')->firstOrFail()->is_active);
    }

    public function test_locale_install_and_configuration_are_atomic_and_preserve_base(): void
    {
        Locale::create(['code' => 'es', 'name' => 'Spanish', 'is_base' => true, 'is_active' => true, 'is_public' => true]);
        $this->withToken($this->token());
        $this->postJson('/mcp/locales', ['install' => 'en', 'locales' => ['en' => ['is_active' => true, 'is_public' => true]]])->assertOk();
        $this->assertTrue(Locale::where('code', 'en')->firstOrFail()->is_public);
        $this->postJson('/mcp/locales', ['locales' => ['es' => ['is_active' => false, 'is_public' => false], 'en' => ['is_active' => false, 'is_public' => true]]])->assertOk();
        $this->assertTrue(Locale::where('code', 'es')->firstOrFail()->is_active);
        $this->assertTrue(Locale::where('code', 'es')->firstOrFail()->is_public);
        $this->assertFalse(Locale::where('code', 'en')->firstOrFail()->is_public);
        $third = array_values(array_diff(Locale::catalogCodes(), ['es', 'en']))[0] ?? null;
        $this->assertNotNull($third, 'Starter must offer a third catalog locale for the atomic installation check.');
        $before = DB::table('content_revisions')->count();
        $this->postJson('/mcp/locales', ['install' => $third, 'locales' => [$third => ['is_public' => 'invalid']]])->assertUnprocessable();
        $this->assertDatabaseMissing('locales', ['code' => $third]);
        $this->assertSame($before, DB::table('content_revisions')->count());
    }

    public function test_rpc_retries_and_stale_revisions_remain_protocol_errors(): void
    {
        $this->withToken($this->token());
        foreach (['pages', 'posts'] as $type) {
            $input = ['title' => 'RPC original', 'slug' => 'rpc-original', 'operation_key' => 'rpc-'.$type];
            if ($type === 'pages') {
                $input['template_key'] = 'default';
            }
            $response = $this->rpc($type.'_create_draft', $input)->assertOk()->assertJsonPath('result.isError', false);
            $record = json_decode($response->json('result.content.0.text'), true)['data'];
            $retry = $this->rpc($type.'_create_draft', $input)->assertOk()->assertJsonPath('result.isError', false);
            $this->assertSame($record['id'], json_decode($retry->json('result.content.0.text'), true)['data']['id']);
            $args = [rtrim($type, 's').'_id' => $record['id'], 'title' => 'RPC saved', 'expected_revision' => $record['revision']];
            $this->rpc($type.'_update_draft', $args)->assertOk()->assertJsonPath('result.isError', false);
            $this->rpc($type.'_update_draft', array_replace($args, ['title' => 'RPC lost']))->assertOk()->assertJsonPath('error.code', -32009)->assertJsonPath('error.data.status', 409);
            $this->rpc($type.'_publish', [rtrim($type, 's').'_id' => $record['id'], 'expected_revision' => $record['revision']])->assertOk()->assertJsonPath('error.code', -32009);
            $this->rpc($type.'_create_draft', array_replace($input, ['title' => 'Different']))->assertOk()->assertJsonPath('error.code', -32009);
        }
    }

    public function test_generic_rpc_translation_publication_and_preview_tools_are_callable(): void
    {
        $this->locales();
        $this->withToken($this->token());
        foreach (['pages', 'posts'] as $type) {
            $input = ['title' => 'RPC source', 'slug' => 'rpc-source'];
            if ($type === 'pages') {
                $input['template_key'] = 'default';
            }
            $source = $this->postJson('/mcp/'.$type.'/drafts', $input)->assertCreated()->json('data');
            $response = $this->rpc('content_translate', ['type' => $type, 'id' => $source['id'], 'locale' => 'en'])->assertOk()->assertJsonPath('result.isError', false);
            $translation = json_decode($response->json('result.content.0.text'), true)['data'];
            $this->assertSame($source['translation_group_id'], $translation['translation_group_id']);
            $this->rpc('content_translation_changes', ['type' => $type, 'id' => $translation['id']])->assertOk()->assertJsonPath('result.isError', false);
            $this->rpc('content_publication', ['type' => $type, 'id' => $translation['id'], 'action' => 'schedule', 'published_at' => now()->addDay()->toIso8601String(), 'expected_revision' => $translation['revision']])->assertOk()->assertJsonPath('result.isError', false);
            $this->rpc('content_publication', ['type' => $type, 'id' => $translation['id'], 'action' => 'unpublish'])->assertOk()->assertJsonPath('result.isError', false);
            $preview = $this->rpc('content_preview', ['type' => $type, 'id' => $translation['id'], 'hours' => 1])->assertOk()->assertJsonPath('result.isError', false);
            $url = json_decode($preview->json('result.content.0.text'), true)['data']['url'];
            $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }
    }

    public function test_creation_retries_and_revision_conflicts_for_pages_and_posts(): void
    {
        $this->withToken($this->token());
        foreach (['pages', 'posts'] as $type) {
            $input = ['title' => 'Original', 'slug' => 'original', 'operation_key' => 'retry-'.$type];
            if ($type === 'pages') {
                $input['template_key'] = 'default';
            }
            $first = $this->postJson('/mcp/'.$type.'/drafts', $input)->assertCreated();
            $id = $first->json('data.id');
            $revision = $first->json('data.revision');
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $revision);
            $this->postJson('/mcp/'.$type.'/drafts', $input)->assertCreated()->assertJsonPath('data.id', $id);
            $this->postJson('/mcp/'.$type.'/drafts', array_replace($input, ['title' => 'Different']))->assertConflict();
            $this->patchJson('/mcp/'.$type.'/'.$id.'/draft', ['title' => 'New', 'expected_revision' => $revision])->assertOk();
            $this->patchJson('/mcp/'.$type.'/'.$id.'/draft', ['title' => 'Lost', 'expected_revision' => $revision])->assertConflict();
            $this->getJson('/mcp/'.$type.'/'.$id)->assertOk()->assertJsonPath('data.title', 'New');
        }
        $this->assertSame(1, Page::count());
        $this->assertSame(1, Post::count());
        $this->assertSame(2, DB::table('mcp_operation_receipts')->count());
    }

    public function test_translation_groups_and_source_acknowledgements_for_pages_and_posts(): void
    {
        $this->locales();
        $this->withToken($this->token());
        foreach (['pages', 'posts'] as $type) {
            $input = ['title' => 'Source', 'slug' => 'source'];
            if ($type === 'pages') {
                $input['template_key'] = 'default';
            }
            $source = $this->postJson('/mcp/'.$type.'/drafts', $input)->assertCreated()->json('data');
            $translation = $this->postJson('/mcp/content/'.$type.'/'.$source['id'].'/translation', ['locale' => 'en'])->assertOk()->json('data');
            $this->assertSame($source['translation_group_id'], $translation['translation_group_id']);
            $this->assertSame('en', $translation['locale']);
            $this->assertSame('draft', $translation['status']);
            $field = $type === 'pages' ? 'meta.title' : 'title';
            $patch = $type === 'pages' ? ['content_json' => ['meta' => ['title' => 'Changed source']]] : ['title' => 'Changed source'];
            $this->patchJson('/mcp/'.$type.'/'.$source['id'].'/draft', $patch)->assertOk();
            $changes = $this->getJson('/mcp/content/'.$type.'/'.$translation['id'].'/translation-changes')->assertOk()->json('data');
            $this->assertContains($field, $changes['stale_fields']);
            $this->postJson('/mcp/content/'.$type.'/'.$translation['id'].'/translation-acknowledge', ['field' => $field, 'base_hash' => str_repeat('0', 64)])->assertConflict();
            $this->postJson('/mcp/content/'.$type.'/'.$translation['id'].'/translation-acknowledge', ['field' => $field, 'base_hash' => $changes['changes'][$field]['hash']])->assertOk();
            $changes = $this->getJson('/mcp/content/'.$type.'/'.$translation['id'].'/translation-changes')->assertOk()->json('data');
            $this->assertNotContains($field, $changes['stale_fields']);
        }
    }

    public function test_restricted_scopes_reject_new_writes_on_both_transports_without_mutations(): void
    {
        $this->locales();
        $page = Page::create(['title' => 'Live', 'slug' => 'live', 'template_key' => 'default', 'status' => 'published', 'published_at' => now()]);
        $before = [Page::count(), DB::table('editorial_versions')->count(), DB::table('editorial_preview_links')->count()];
        $this->withToken($this->token('admin', 'read-only'));
        foreach (['translation' => ['locale' => 'en'], 'translation-acknowledge' => ['field' => 'meta.title', 'base_hash' => str_repeat('0', 64)], 'publication' => ['action' => 'unpublish'], 'preview' => []] as $path => $body) {
            $this->postJson('/mcp/content/pages/'.$page->id.'/'.$path, $body)->assertForbidden();
        }
        foreach (['content_translate' => ['locale' => 'en'], 'content_translation_acknowledge' => ['field' => 'meta.title', 'base_hash' => str_repeat('0', 64)], 'content_publication' => ['action' => 'unpublish'], 'content_preview' => []] as $tool => $body) {
            $this->rpc($tool, $body + ['type' => 'pages', 'id' => $page->id])->assertOk()->assertJsonPath('error.code', -32003);
        }
        $this->withToken($this->token('admin', 'draft-only'));
        $this->postJson('/mcp/content/pages/'.$page->id.'/translation-acknowledge', ['field' => 'meta.title', 'base_hash' => str_repeat('0', 64)])->assertForbidden();
        $this->rpc('content_translation_acknowledge', ['type' => 'pages', 'id' => $page->id, 'field' => 'meta.title', 'base_hash' => str_repeat('0', 64)])->assertOk()->assertJsonPath('error.code', -32003);
        $this->rpc('content_publication', ['type' => 'pages', 'id' => $page->id, 'action' => 'unpublish'])->assertOk()->assertJsonPath('error.code', -32003);
        $this->rpc('media_authorize_upload')->assertOk()->assertJsonPath('error.code', -32003);
        $this->postJson('/mcp/media/upload-authorization')->assertForbidden();
        $this->assertSame($before, [Page::count(), DB::table('editorial_versions')->count(), DB::table('editorial_preview_links')->count()]);
        $this->assertSame('published', $page->fresh()->status);
    }

    public function test_scheduling_and_unpublishing_require_administrator_and_full_scope(): void
    {
        $this->withToken($this->token());
        foreach (['pages', 'posts'] as $type) {
            $input = ['title' => 'Schedule', 'slug' => 'schedule'];
            if ($type === 'pages') {
                $input['template_key'] = 'default';
            }
            $record = $this->postJson('/mcp/'.$type.'/drafts', $input)->assertCreated()->json('data');
            $this->postJson('/mcp/content/'.$type.'/'.$record['id'].'/publication', ['action' => 'schedule', 'published_at' => now()->addDay()->toIso8601String()])->assertOk()->assertJsonPath('data.status', 'published');
            $class = $type === 'pages' ? Page::class : Post::class;
            $this->assertFalse($class::findOrFail($record['id'])->isPublished());
            $this->postJson('/mcp/content/'.$type.'/'.$record['id'].'/publication', ['action' => 'unpublish'])->assertOk()->assertJsonPath('data.status', 'draft');
            $this->withToken($this->token('editor'))->postJson('/mcp/content/'.$type.'/'.$record['id'].'/publication', ['action' => 'schedule', 'published_at' => now()->addDay()->toIso8601String()])->assertForbidden();
            $this->withToken($this->token('admin', 'draft-only'))->postJson('/mcp/content/'.$type.'/'.$record['id'].'/publication', ['action' => 'unpublish'])->assertForbidden();
            $this->withToken($this->token('admin'));
        }
    }
}
