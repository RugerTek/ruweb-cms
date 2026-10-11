<?php

namespace App\Services\Forms;

use App\Exceptions\RevisionConflict;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Actor-aware inbox operations shared by the panel and MCP. Visitor fields are immutable. */
final class SubmissionOperations
{
    public function __construct(private FormRegistry $forms, private SubmissionNotifier $notifier) {}

    public function authorize(User $actor): void
    {
        if (! in_array($actor->role, ['admin', 'editor'], true)) {
            throw new AuthorizationException('Unauthorized role for submission operation.');
        }
    }

    public function query(User $actor, string $form, array $input = []): Builder
    {
        $this->authorize($actor);
        $config = $this->forms->get($form);
        $data = Validator::make($input, [
            'status' => ['nullable', Rule::in(['new', 'read'])],
            'workflow' => ['nullable', Rule::in(['new', 'in_progress', 'resolved'])],
            'assignee' => ['nullable', 'regex:/^(unassigned|[0-9]+)$/'],
            'notification' => ['nullable', Rule::in(['queued', 'retrying', 'sending', 'failed', 'sent', 'skipped'])],
            'search' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        $query = $this->forms->query($form)->with('assignee')->latest('id');
        if (($data['status'] ?? null) === 'new') {
            $query->whereNull('read_at');
        } elseif (($data['status'] ?? null) === 'read') {
            $query->whereNotNull('read_at');
        }
        if ($data['workflow'] ?? null) {
            $query->where('workflow_status', $data['workflow']);
        }
        if (($data['assignee'] ?? null) === 'unassigned') {
            $query->whereNull('assigned_to');
        } elseif (isset($data['assignee'])) {
            $query->where('assigned_to', (int) $data['assignee']);
        }
        if ($data['notification'] ?? null) {
            $query->where('notification_status', $data['notification']);
        }
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $query->where(function ($query) use ($config, $search) {
                foreach ($config['searchable'] ?? [] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $comparison) {
            if ($data[$key] ?? null) {
                $query->whereDate('created_at', $comparison, $data[$key]);
            }
        }

        return $query;
    }

    public function record(User $actor, string $form, int $id): Model
    {
        $this->authorize($actor);

        return $this->forms->query($form)->findOrFail($id);
    }

    public function fingerprint(Model $record): string
    {
        $notes = $record->notes()->selectRaw('COUNT(*) as total, MAX(id) as latest')->first();

        return hash('sha256', json_encode([$record->attributesToArray(), $notes?->total, $notes?->latest], JSON_THROW_ON_ERROR));
    }

    private function describe(Model $record, array $input = [], bool $includeNotes = true): array
    {
        $data = $record->attributesToArray();
        unset($data['notification_token'], $data['notification_claim'], $data['notification_email'], $data['notification_error']);
        $data['assignee'] = $this->person($record->assignee);
        $data['revision'] = $this->fingerprint($record);
        if ($record->notification_status === 'failed') {
            $data['notification_diagnostic'] = ['cause' => 'delivery_failed', 'recovery' => 'Check mail settings and the queue worker, then retry notification.'];
        } elseif ($record->notification_status === 'skipped') {
            $data['notification_diagnostic'] = ['cause' => 'delivery_skipped', 'recovery' => 'Enable mail and configure notifications on the source page, then retry notification.'];
        }
        $truncated = [];
        foreach ($data as $field => $value) {
            if (is_string($value) && mb_strlen($value) > 8192) {
                $truncated[$field] = ['length' => mb_strlen($value), 'sha256' => hash('sha256', $value)];
                $data[$field] = mb_substr($value, 0, 8192);
            }
        }
        $data['truncated_fields'] = $truncated;
        if ($includeNotes) {
            $notes = $record->notes()->with('author')->orderBy('id')->paginate(20, ['*'], 'notes_page', $input['notes_page'] ?? 1);
            $data['notes'] = $notes->getCollection()->map(fn ($note) => ['id' => $note->id, 'body' => $note->body,
                'author' => $this->person($note->author), 'created_at' => $note->created_at?->toISOString()])->all();
            $data['notes_pagination'] = ['page' => $notes->currentPage(), 'per_page' => 20, 'total' => $notes->total(), 'last_page' => $notes->lastPage()];
        }
        if (isset($input['field'])) {
            $value = (string) $record->getAttribute($input['field']);
            $offset = $input['offset'] ?? 0;
            $length = $input['length'] ?? 8192;
            $slice = mb_substr($value, $offset, $length);
            $data['field_slice'] = ['field' => $input['field'], 'offset' => $offset, 'length' => mb_strlen($slice), 'total_characters' => mb_strlen($value), 'has_more' => $offset + $length < mb_strlen($value),
                'sha256' => hash('sha256', $value), 'value' => $slice];
        }

        return $data;
    }

    private function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => mb_substr($user->name, 0, 255), 'role' => $user->role] : null;
    }

    public function read(User $actor, string $form, ?int $id, array $input = []): array
    {
        return $id === null ? $this->list($actor, $form, $input) : $this->get($actor, $form, $id, $input);
    }

    public function list(User $actor, string $form, array $input = []): array
    {
        $data = Validator::make($input, ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']])->validate();
        $records = $this->query($actor, $form, $input)->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);

        return ['form' => $form, 'submissions' => $records->getCollection()->map(fn ($record) => $this->describe($record, [], false))->all(),
            'page' => $records->currentPage(), 'per_page' => $records->perPage(), 'total' => $records->total(), 'last_page' => $records->lastPage()];
    }

    public function get(User $actor, string $form, int $id, array $input = []): array
    {
        $record = $this->record($actor, $form, $id);
        $fields = array_values(array_filter($record->getFillable(), fn ($field) => is_string($record->getAttribute($field)) && ! in_array($field, ['workflow_status'], true)));
        $data = Validator::make($input, ['notes_page' => ['nullable', 'integer', 'min:1'],
            'field' => ['nullable', Rule::in($fields)], 'offset' => ['nullable', 'integer', 'min:0'],
            'length' => ['nullable', 'integer', 'min:1', 'max:8192']])->validate();

        return $this->describe($record, $data);
    }

    private function audit(User $actor, string $form, int $id, string $action, array $before, array $after): void
    {
        DB::table('submission_events')->insert(['user_id' => $actor->id, 'form' => $form, 'submission_id' => $id,
            'action' => $action, 'before' => json_encode($before, JSON_THROW_ON_ERROR), 'after' => json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function write(User $actor, string $form, int $id, string $action, array $input): array
    {
        $this->authorize($actor);
        $this->forms->get($form);
        abort_unless(in_array($action, ['assign', 'set_status', 'add_note', 'retry_notification', 'update_workflow', 'mark_read', 'delete'], true), 404);
        $rules = match ($action) {
            'assign' => ['assigned_to' => ['present', 'nullable', 'integer', Rule::exists('users', 'id')->whereIn('role', ['admin', 'editor'])]],
            'set_status' => ['workflow_status' => ['required', Rule::in(['new', 'in_progress', 'resolved'])]],
            'update_workflow' => ['workflow_status' => ['required', Rule::in(['new', 'in_progress', 'resolved'])], 'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->whereIn('role', ['admin', 'editor'])]],
            'add_note' => ['body' => ['required', 'string', 'max:5000', 'regex:/\S/u']],
            default => [],
        };
        $data = Validator::make($input, $rules + [
            'expected_revision' => ['nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/'],
            'operation_key' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_.:-]+$/'],
        ])->validate();
        $payload = ['form' => $form, 'id' => $id, 'input' => $data];
        unset($payload['input']['operation_key']);
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $form, $id, $action, $data, $hash) {
            // Actor locking serializes operation-key retries before the row/revision check.
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $key = $data['operation_key'] ?? null;
            $receipt = DB::table('submission_operation_receipts')->where(['user_id' => $actor->id, 'action' => $action, 'operation_key' => $key]);
            if ($key !== null && ($previous = $receipt->first())) {
                abort_unless(hash_equals($previous->payload_hash, $hash), 409, 'Operation key already used with different input.');

                return json_decode($previous->result, true, 512, JSON_THROW_ON_ERROR);
            }
            $record = $this->forms->query($form)->lockForUpdate()->findOrFail($id);
            if (isset($data['expected_revision'])) {
                $current = $this->fingerprint($record);
                if (! hash_equals($current, $data['expected_revision'])) {
                    throw new RevisionConflict($data['expected_revision'], $current);
                }
            }
            $before = ['workflow_status' => $record->workflow_status, 'assigned_to' => $record->assigned_to, 'notification_status' => $record->notification_status];
            $extra = [];
            switch ($action) {
                case 'assign':
                    $record->update(['assigned_to' => $data['assigned_to']]);
                    break;
                case 'set_status':
                    $record->update(['workflow_status' => $data['workflow_status']]);
                    break;
                case 'update_workflow':
                    $record->update(['workflow_status' => $data['workflow_status'], 'assigned_to' => $data['assigned_to'] ?? null]);
                    break;
                case 'add_note':
                    $record->notes()->create(['body' => $data['body'], 'user_id' => $actor->id]);
                    break;
                case 'retry_notification':
                    $queued = $this->notifier->notify($record, $record->page, $this->forms->get($form)['section'] ?? 'form');
                    $section = $record->page?->sections()[$this->forms->get($form)['section'] ?? 'form'] ?? [];
                    $reason = match (true) {
                        $queued => 'queued',
                        $record->notified_at !== null || $record->notification_status === 'sent' => 'already_sent',
                        in_array($record->notification_status, ['queued', 'sending', 'retrying'], true) => 'delivery_in_progress',
                        ! ($section['notify_enabled'] ?? false) || ! filter_var($section['notify_email'] ?? '', FILTER_VALIDATE_EMAIL) => 'notification_not_configured',
                        ! Setting::get('mail_enabled') => 'mail_disabled',
                        default => 'queue_unavailable',
                    };
                    $extra = ['queued' => $queued, 'reason' => $reason, 'recovery' => match ($reason) {
                        'mail_disabled' => 'Enable mail in settings and retry.',
                        'notification_not_configured' => 'Enable form notifications and set a valid recipient on the source page.',
                        'queue_unavailable' => 'Check the queue connection and retry.',
                        'delivery_in_progress' => 'Wait for the current delivery to finish.',
                        default => null,
                    }];
                    break;
                case 'mark_read':
                    $record->markRead();
                    break;
                case 'delete':
                    $record->delete();
                    $this->audit($actor, $form, $id, $action, $before, ['deleted' => true]);

                    return ['deleted' => true, 'id' => $id];
            }
            $record->refresh();
            $this->audit($actor, $form, $id, $action, $before, ['workflow_status' => $record->workflow_status,
                'assigned_to' => $record->assigned_to, 'notification_status' => $record->notification_status] + $extra);
            $result = $this->describe($record) + $extra;
            if ($key !== null) {
                DB::table('submission_operation_receipts')->insert(['user_id' => $actor->id, 'action' => $action, 'operation_key' => $key,
                    'payload_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }

            return $result;
        }, 3);
    }
}
