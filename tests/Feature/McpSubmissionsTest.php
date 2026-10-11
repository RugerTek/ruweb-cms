<?php

namespace Tests\Feature;

use App\Mcp\ToolRegistry;
use App\Models\ContactSubmission;
use App\Models\McpApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class McpSubmissionsTest extends TestCase
{
    use RefreshDatabase;

    public static function profiles(): array
    {
        return [['full'], ['essential']];
    }

    private function record(): ContactSubmission
    {
        return ContactSubmission::create(['locale' => 'es', 'name' => 'Visitor', 'email' => 'visitor@example.test', 'subject' => 'Follow-up', 'message' => 'Immutable visitor message']);
    }

    private function rpcCall(string $tool, array $input)
    {
        return $this->postJson('/mcp/rpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $input]]);
    }

    #[DataProvider('profiles')]
    public function test_reads_do_not_mark_read_and_full_editor_writes_share_panel_state(string $profile): void
    {
        config(['cms.admin_profile' => $profile]);
        $actor = User::factory()->create(['role' => 'editor']);
        $key = McpApiKey::issue($actor, 'Inbox', 'full', null);
        $record = $this->record();
        $this->withToken($key)->getJson('/mcp/submissions/contact?search=Follow-up')->assertOk()->assertJsonPath('data.total', 1);
        $detail = $this->getJson('/mcp/submissions/contact/'.$record->id)->assertOk()->json('data');
        $this->assertNull($record->fresh()->read_at);
        $base = ['form' => 'contact', 'id' => $record->id, 'expected_revision' => $detail['revision'], 'operation_key' => 'assign-1', 'assigned_to' => $actor->id];
        $assigned = $this->rpcCall('submissions_assign', $base)->assertOk();
        $body = json_decode($assigned->json('result.content.0.text'), true)['data'];
        $this->assertSame($actor->id, $body['assigned_to']);
        $note = ['form' => 'contact', 'id' => $record->id, 'expected_revision' => $body['revision'], 'operation_key' => 'note-1', 'body' => 'Internal note <script>alert(1)</script>'];
        $this->rpcCall('submissions_add_note', $note)->assertOk();
        $this->rpcCall('submissions_add_note', $note)->assertOk();
        $this->assertSame(1, $record->notes()->count());
        $this->assertSame($actor->id, $record->notes()->first()->user_id);
        $fresh = $this->getJson('/mcp/submissions/contact/'.$record->id)->json('data');
        $this->postJson('/mcp/submissions/contact/'.$record->id.'/set-status', ['expected_revision' => $fresh['revision'], 'operation_key' => 'resolve-1', 'workflow_status' => 'resolved', 'message' => 'Forbidden overwrite'])->assertOk();
        $this->assertSame('Immutable visitor message', $record->fresh()->message);
        $this->assertNull($record->fresh()->read_at);
        $this->actingAs($actor)->get(route('admin.submissions.show', ['form' => 'contact', 'submission' => $record->id]))->assertOk()->assertSee('Internal note &lt;script&gt;', false);
        $this->assertNotNull($record->fresh()->read_at);
        $this->assertSame('resolved', $record->fresh()->workflow_status);
    }

    public function test_credentials_and_roles_are_independent_and_denials_do_not_mutate(): void
    {
        $record = $this->record();
        foreach (['read-only', 'draft-only'] as $scope) {
            $actor = User::factory()->create(['role' => 'admin']);
            $this->withToken(McpApiKey::issue($actor, $scope, $scope, null));
            $this->rpcCall('submissions_get', ['form' => 'contact', 'id' => $record->id])->assertOk();
            foreach (['assign', 'set_status', 'add_note', 'retry_notification'] as $action) {
                $this->rpcCall('submissions_'.$action, ['form' => 'contact', 'id' => $record->id])->assertOk()->assertJsonPath('error.code', -32003)->assertJsonPath('error.message', __('security.scope_denied'))->assertJsonPath('error.data.reason', 'scope_denied');
                $this->postJson('/mcp/submissions/contact/'.$record->id.'/'.str_replace('_', '-', $action), [])->assertForbidden();
            }
        }
        $viewer = User::factory()->create(['role' => 'author']);
        $this->withToken(McpApiKey::issue($viewer, 'Full low role', 'full', null))->getJson('/mcp/submissions/contact/'.$record->id)->assertForbidden();
        $this->assertNull($record->fresh()->read_at);
        $this->assertSame(0, $record->notes()->count());
        $this->assertSame('new', $record->fresh()->workflow_status);
    }

    public function test_mutations_require_revision_key_and_report_stale_or_changed_retries(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $record = $this->record();
        $this->withToken(McpApiKey::issue($actor, 'Full', 'full', null));
        $this->postJson('/mcp/submissions/contact/'.$record->id.'/add-note', ['body' => 'note'])->assertUnprocessable()->assertJsonValidationErrors(['expected_revision', 'operation_key']);
        $revision = $this->getJson('/mcp/submissions/contact/'.$record->id)->json('data.revision');
        $input = ['expected_revision' => $revision, 'operation_key' => 'note-original', 'body' => 'one'];
        $this->postJson('/mcp/submissions/contact/'.$record->id.'/add-note', $input)->assertOk();
        $this->postJson('/mcp/submissions/contact/'.$record->id.'/add-note', array_replace($input, ['body' => 'different']))->assertConflict();
        $this->rpcCall('submissions_set_status', ['form' => 'contact', 'id' => $record->id, 'expected_revision' => $revision, 'operation_key' => 'stale', 'workflow_status' => 'resolved'])->assertOk()->assertJsonPath('error.code', -32009);
        $this->assertSame(1, $record->notes()->count());
        $this->assertSame('new', $record->fresh()->workflow_status);
        $this->assertSame(1, DB::table('submission_operation_receipts')->count());
    }

    public function test_registered_forms_and_bounded_inputs_are_enforced_without_arbitrary_dispatch(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $this->withToken(McpApiKey::issue($actor, 'Full', 'full', null));
        $this->getJson('/mcp/submissions/users')->assertNotFound();
        $this->getJson('/mcp/submissions/contact?per_page=101')->assertUnprocessable();
        $this->getJson('/mcp/submissions/contact?search='.str_repeat('x', 501))->assertUnprocessable();
        $this->rpcCall('submissions_get', [])->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpcCall('submissions_list', ['form' => 'App\\Models\\User'])->assertOk()->assertJsonPath('error.code', -32602);
        $names = array_column(ToolRegistry::all(), 'name');
        foreach (['list', 'get', 'assign', 'set_status', 'add_note', 'retry_notification'] as $action) {
            $this->assertContains('submissions_'.$action, $names);
        }
    }

    public function test_large_visitor_text_and_notes_are_recoverable_without_leaking_transport_secrets(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $record = $this->record();
        $text = str_repeat('á😀', 5000);
        $record->forceFill(['message' => $text, 'notification_token' => 'hidden-token', 'notification_email' => 'private@example.test', 'notification_error' => 'Bearer secret-in-trace'])->save();
        for ($i = 0; $i < 23; $i++) {
            $record->notes()->create(['user_id' => $actor->id, 'body' => 'Note '.$i]);
        }
        $this->withToken(McpApiKey::issue($actor, 'Full', 'full', null));
        $data = $this->getJson('/mcp/submissions/contact/'.$record->id)->assertOk()->json('data');
        $this->assertSame(8192, mb_strlen($data['message']));
        $this->assertSame(hash('sha256', $text), $data['truncated_fields']['message']['sha256']);
        $this->assertCount(20, $data['notes']);
        $this->assertSame(23, $data['notes_pagination']['total']);
        foreach (['notification_token', 'notification_email', 'notification_error', 'notification_claim'] as $field) {
            $this->assertArrayNotHasKey($field, $data);
        }
        $this->assertSame(['id', 'name', 'role'], array_keys($data['notes'][0]['author']));
        $slice = $this->getJson('/mcp/submissions/contact/'.$record->id.'?field=message&offset=8192&length=8192&notes_page=2')->assertOk()->json('data');
        $this->assertSame($text, $data['message'].$slice['field_slice']['value']);
        $this->assertSame($data['revision'], $slice['revision']);
        $this->assertCount(3, $slice['notes']);
        $this->getJson('/mcp/submissions/contact/'.$record->id.'?field=notification_token')->assertUnprocessable();
        $this->getJson('/mcp/submissions/contact/'.$record->id.'?length=8193')->assertUnprocessable();
        $this->getJson('/mcp/submissions/contact/'.$record->id.'?notes_page=0')->assertUnprocessable();
        $list = $this->getJson('/mcp/submissions/contact')->assertOk()->json('data.submissions.0');
        $this->assertArrayNotHasKey('notes', $list);
        $this->assertNull($record->fresh()->read_at);
    }
}
