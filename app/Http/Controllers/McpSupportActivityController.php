<?php

namespace App\Http\Controllers;

use App\Models\SupportAudit;
use App\Models\SupportSession;
use App\Services\SupportAccess\SupportAccessSwitch;
use App\Services\SupportAccess\SupportActivitySummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * `support_activity_list` and its REST mirror GET /mcp/support-activity (docs/operations/mcp.md#support-activity):
 * read-only view of Admin > Actividad de soporte for administrators. Like the screen it holds field names, never
 * values; there is no tool to change or delete the record. `meta.access` says whether the site allows support access
 * (the switch itself is panel-only: a support account is an administrator too and must never flip it).
 */
class McpSupportActivityController extends Controller
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /** Changes listed per session in the list; one session (session_id) returns every entry up to ENTRIES_MAX. */
    public const CHANGES_PER_SESSION = 50;

    public const ENTRIES_MAX = 500;

    public function __construct(private readonly SupportActivitySummary $summary, private readonly SupportAccessSwitch $switch) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Support activity is available to administrators only.');
        $validated = Validator::make($request->all(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'session_id' => ['sometimes', 'integer', 'min:1'],
        ])->validate();

        if (isset($validated['session_id'])) {
            $session = SupportSession::query()->findOrFail($validated['session_id']);
            $entries = $session->audits()->orderBy('at')->orderBy('id')->limit(self::ENTRIES_MAX)->get();

            return response()->json(['data' => $this->session($session) + [
                'entries' => $entries->map(fn (SupportAudit $audit) => $this->entry($audit))->values()->all(),
                'entries_truncated' => $entries->count() >= self::ENTRIES_MAX,
            ]]);
        }

        $sessions = SupportSession::query()
            ->withCount(['audits as page_views_count' => fn ($query) => $query->whereIn('method', ['GET', 'HEAD'])])
            ->orderByDesc('started_at')->orderByDesc('id')
            ->limit((int) ($validated['limit'] ?? self::DEFAULT_LIMIT))->get();

        return response()->json([
            'data' => $sessions->map(function (SupportSession $session): array {
                $changes = $session->audits()->whereNotIn('method', ['GET', 'HEAD'])->orderBy('at')->orderBy('id')->limit(self::CHANGES_PER_SESSION + 1)->get();

                return $this->session($session) + [
                    'page_views_count' => (int) $session->page_views_count,
                    'changes' => $changes->take(self::CHANGES_PER_SESSION)->map(fn (SupportAudit $audit) => $this->entry($audit))->values()->all(),
                    'changes_truncated' => $changes->count() > self::CHANGES_PER_SESSION,
                ];
            })->values()->all(),
            'meta' => ['retention_days' => (int) config('cms.support_access.retention_days', 365), 'access' => $this->access()],
        ]);
    }

    /** @return array{allowed: bool, changed_by: ?string, changed_at: ?string} */
    private function access(): array
    {
        $state = $this->switch->state();

        return ['allowed' => $state['allowed'], 'changed_by' => $state['changed_by'], 'changed_at' => $state['changed_at']?->toIso8601String()];
    }

    /** @return array<string, mixed> */
    private function session(SupportSession $session): array
    {
        return [
            'id' => $session->id,
            'operator_email' => $session->operator_email,
            'operator_name' => $session->operator_name,
            'role' => $session->role,
            'reason' => $session->reason,
            'ticket' => $session->ticket,
            'issuer' => $session->issuer,
            'ip' => $session->ip,
            'started_at' => $session->started_at->toIso8601String(),
            'expires_at' => $session->expires_at->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'end_reason' => $session->end_reason,
            'state' => $session->state(),
            'duration_minutes' => $session->durationMinutes(),
        ];
    }

    /** @return array<string, mixed> */
    private function entry(SupportAudit $audit): array
    {
        return [
            'at' => $audit->at->toIso8601String(),
            'kind' => $audit->isWrite() ? 'change' : 'page_view',
            'method' => $audit->method,
            'route_name' => $audit->route_name,
            'path' => $audit->path,
            'subject_type' => $audit->subject_type,
            'subject_id' => $audit->subject_id,
            'subject_label' => $audit->subject_label,
            'changed_fields' => $audit->changed_fields ?? [],
            'status' => $audit->status,
            'summary' => $this->summary->describe($audit),
        ];
    }
}
