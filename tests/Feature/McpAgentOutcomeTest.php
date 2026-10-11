<?php

namespace Tests\Feature;

use App\Models\Locale;
use App\Models\McpApiKey;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** What an agent observes after each MCP write: one version per operation, explicit refusals and discarded markup. */
class McpAgentOutcomeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['es', 'en'] as $locale) {
            Locale::create(['code' => $locale, 'name' => $locale, 'is_base' => $locale === 'es', 'is_active' => true, 'is_public' => true]);
        }
        $this->withToken(McpApiKey::issue(User::factory()->create(['role' => 'admin']), 'Outcome', 'full', null));
    }

    private function rpc(string $tool, array $args = []): TestResponse
    {
        return $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $args]]);
    }

    private function rpcData(TestResponse $response): array
    {
        $response->assertOk()->assertJsonMissingPath('error')->assertJsonPath('result.isError', false);

        return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function versions(int $pageId): array
    {
        return DB::table('editorial_versions')->where('content_type', 'pages')->where('content_id', $pageId)
            ->where('is_proposal', false)->orderBy('id')->pluck('fingerprint')->all();
    }

    private function createDraft(string $slug, array $content = []): array
    {
        return $this->rpcData($this->rpc('pages_create_draft', ['title' => 'Outcome', 'slug' => $slug, 'template_key' => 'default', 'locale' => 'es',
            'operation_key' => $slug, 'content_json' => $content ?: ['meta' => ['title' => 'SEO'], 'sections' => ['content' => ['is_visible' => true, 'heading' => 'Version A', 'body' => 'Version A']]]]))['data'];
    }

    public function test_each_write_records_exactly_one_restorable_version_of_its_result(): void
    {
        $page = $this->createDraft('one-version');
        $this->assertSame([$page['revision']], $this->versions($page['id']));

        $updated = $this->rpcData($this->rpc('pages_update_draft', ['page_id' => $page['id'], 'expected_revision' => $page['revision'],
            'content_json' => ['sections' => ['content' => ['heading' => 'Version B', 'body' => 'Version B']]]]))['data'];
        $this->assertSame([$page['revision'], $updated['revision']], $this->versions($page['id']));

        $versionA = DB::table('editorial_versions')->where('content_id', $page['id'])->where('fingerprint', $page['revision'])->value('id');
        $restored = $this->rpcData($this->rpc('content_restore', ['type' => 'pages', 'id' => $page['id'], 'version_id' => $versionA, 'mode' => 'draft',
            'expected_revision' => $updated['revision'], 'operation_key' => 'restore-a']))['data'];
        $this->assertSame($page['revision'], $restored['revision']);
        $this->assertSame([$page['revision'], $updated['revision'], $page['revision']], $this->versions($page['id']));
        $this->assertSame('Version A', Page::findOrFail($page['id'])->content_json['sections']['content']['heading']);
        $this->assertSame('draft', Page::findOrFail($page['id'])->status);
    }

    public function test_a_failed_operation_leaves_no_version_and_does_not_leak_into_the_next_one(): void
    {
        $page = $this->createDraft('rolled-back');
        try {
            app(ContentRevisionLedger::class)->transaction(function () use ($page) {
                Page::findOrFail($page['id'])->update(['title' => 'Never committed']);
                throw new \RuntimeException('Failure after a write.');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame([$page['revision']], $this->versions($page['id']));
        $this->assertSame('Outcome', Page::findOrFail($page['id'])->title);

        $updated = $this->rpcData($this->rpc('pages_update_draft', ['page_id' => $page['id'], 'expected_revision' => $page['revision'], 'title' => 'Committed']))['data'];
        $this->assertSame([$page['revision'], $updated['revision']], $this->versions($page['id']));
    }

    public function test_resubmitting_stored_content_in_another_key_order_is_not_a_change(): void
    {
        $page = $this->createDraft('same-content');
        $stored = Page::findOrFail($page['id']);
        // MySQL JSON columns hand keys back in their own order; emulate it on any driver.
        DB::table('pages')->where('id', $stored->id)->update(['content_json' => json_encode(['sections' => ['content' => ['body' => 'Version A', 'heading' => 'Version A', 'is_visible' => true]], 'meta' => ['title' => 'SEO']]), 'updated_at' => now()->subDay()]);
        $touched = Page::findOrFail($stored->id)->updated_at;
        $revision = $this->rpcData($this->rpc('pages_get', ['page_id' => $stored->id]))['data']['revision'];

        $this->travel(1)->minutes();
        $after = $this->rpcData($this->rpc('pages_update_draft', ['page_id' => $stored->id, 'expected_revision' => $revision,
            'content_json' => ['sections' => ['content' => ['is_visible' => true, 'heading' => 'Version A', 'body' => 'Version A']]]]))['data'];
        $this->assertSame($revision, $after['revision']);
        $this->assertEquals($touched, Page::findOrFail($stored->id)->updated_at);
    }

    public function test_discarded_composition_markup_is_reported_instead_of_a_silent_success(): void
    {
        $allowed = '<section class="cms-section"><h2>Heading</h2><p>Copy</p><ul><li>One</li></ul></section>';
        $page = $this->rpcData($this->rpc('pages_create_draft', ['title' => 'Html', 'slug' => 'html-notice', 'template_key' => 'html', 'operation_key' => 'html-notice',
            'content_json' => ['sections' => ['content' => ['is_visible' => true, 'html' => $allowed]]]]));
        $this->assertArrayNotHasKey('warnings', $page);

        $data = $this->rpcData($this->rpc('pages_update_draft', ['page_id' => $page['data']['id'], 'expected_revision' => $page['data']['revision'],
            'content_json' => ['sections' => ['content' => ['html' => $allowed.'<script>window.__qa = true;</script><p onclick="x()" class="cms-lead rogue">Kept</p>']]]]));
        $this->assertSame('markup_removed', $data['warnings'][0]['code']);
        $this->assertSame('content_json.sections.content.html', $data['warnings'][0]['field']);
        $this->assertSame(['<script>', 'p[onclick]', '.rogue'], $data['warnings'][0]['removed']);
        $this->assertSame($allowed.'<p class="cms-lead">Kept</p>', Page::findOrFail($page['data']['id'])->content_json['sections']['content']['html']);
    }

    public function test_permission_refusals_are_distinguishable_from_server_failures(): void
    {
        $this->withToken(McpApiKey::issue(User::factory()->create(['role' => 'admin']), 'Read only', 'read-only', null));
        $this->rpc('pages_create_draft', ['title' => 'Denied', 'slug' => 'denied', 'template_key' => 'default'])->assertOk()
            ->assertJsonPath('error.code', -32003)->assertJsonPath('error.message', __('security.scope_denied'))
            ->assertJsonPath('error.data.reason', 'scope_denied')->assertJsonPath('error.data.scope', 'read-only')->assertJsonPath('error.data.tool', 'pages_create_draft');
        $this->rpc('custom_code_list')->assertOk()->assertJsonPath('error.code', -32003)->assertJsonPath('error.data.required_scope', 'custom-code');
        $this->assertSame(0, Page::count());
    }

    public function test_invalid_locale_and_template_are_rejected_by_the_server_without_changes(): void
    {
        $response = $this->rpc('pages_create_draft', ['title' => 'Bad locale', 'slug' => 'bad-locale', 'template_key' => 'default', 'locale' => 'xx'])
            ->assertOk()->assertJsonPath('error.code', -32602)->assertJsonStructure(['error' => ['data' => ['locale']]]);
        $this->assertStringStartsWith('Validation failed. locale: ', $response->json('error.message'));
        $this->rpc('pages_create_draft', ['title' => 'Bad template', 'slug' => 'bad-template', 'template_key' => 'missing'])
            ->assertOk()->assertJsonPath('error.code', -32602)->assertJsonStructure(['error' => ['data' => ['template_key']]]);
        $this->assertSame(0, Page::count());

        $page = $this->createDraft('kept');
        $before = Page::findOrFail($page['id'])->getAttributes();
        $this->rpc('pages_update_draft', ['page_id' => $page['id'], 'template_key' => 'missing'])->assertOk()->assertJsonPath('error.code', -32602);
        $this->assertSame($before, Page::findOrFail($page['id'])->getAttributes());
    }

    public function test_an_unknown_setting_is_reported_as_unknown_and_never_created(): void
    {
        $response = $this->rpc('settings_update', ['key' => 'qa_forbidden_setting', 'value' => 'test'])->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame('Setting not found.', json_decode($response->json('result.content.0.text'), true)['message']);
        $this->assertFalse(Setting::where('key', 'qa_forbidden_setting')->exists());
    }
}
