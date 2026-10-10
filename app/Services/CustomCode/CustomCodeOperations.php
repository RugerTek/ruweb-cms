<?php

namespace App\Services\CustomCode;

use App\Exceptions\RevisionConflict;
use App\Models\User;
use App\Support\CustomCodeDestinations;
use App\Support\CustomCodeRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Immutable complete proposals, exact activation, actor-scoped durable retries. */
final class CustomCodeOperations
{
    public function authorize(User $actor): void
    {
        abort_unless($actor->isAdmin() && $actor->can_manage_custom_code, 403, 'Custom Code permission required.');
    }

    public function revision(object $snippet): string
    {
        return hash('sha256', json_encode([$snippet->id, $snippet->generation, $snippet->state, $snippet->active_version_id]));
    }

    private function describe(object $snippet, array $input = [], bool $summary = false): array
    {
        $query = DB::table('custom_code_versions')->where('snippet_id', $snippet->id);
        $count = $query->count(); $page = (int) ($input['versions_page'] ?? 1);
        if (isset($input['version_id'])) $query->where('id', $input['version_id']);
        $versions = $query->latest('id')->skip(isset($input['version_id']) ? 0 : ($page - 1) * 20)->limit($summary ? 1 : 20)->get()->map(fn ($version) => (array) $version)->all();
        foreach ($versions as &$version) {
            $version['payload'] = json_decode($version['payload'], true);
            if ($summary) { $version['code_read'] = ['bytes' => strlen($version['payload']['code']), 'sha256' => hash('sha256', $version['payload']['code']), 'recovery' => 'custom_code_get with id and version_id']; unset($version['payload']['code']); }
        }
        $active = ! $summary && $snippet->active_version_id ? DB::table('custom_code_versions')->where('snippet_id', $snippet->id)->find($snippet->active_version_id) : null;
        return (array) $snippet + ['versions_total' => $count, 'versions_page' => $page, 'versions_last_page' => max(1, (int) ceil($count / 20)), 'active_version' => $active ? array_replace((array) $active, ['payload' => json_decode($active->payload, true)]) : null, 'revision' => $this->revision($snippet), 'versions' => $versions,
            'events' => DB::table('custom_code_events')->where('snippet_id', $snippet->id)->latest('id')->limit(100)->get()->map(fn ($row) => (array) $row)->all()];
    }

    public function list(User $actor, array $input = []): array
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']])->validate();
        $rows = DB::table('custom_code_snippets')->orderBy('id')->paginate($data['per_page'] ?? 20, ['*'], 'page', $data['page'] ?? 1);
        return ['snippets' => $rows->getCollection()->map(fn ($row) => $this->describe($row, [], true))->all(), 'total' => $rows->total(), 'page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'last_page' => $rows->lastPage()];
    }

    public function get(User $actor, int $id, array $input = []): array
    {
        $this->authorize($actor);
        $row = DB::table('custom_code_snippets')->find($id); abort_unless($row, 404);
        $data = Validator::make($input, ['versions_page' => ['nullable', 'integer', 'min:1'], 'version_id' => ['nullable', 'integer', 'min:1']])->validate();
        return $this->describe($row, $data);
    }

    public function normalize(array $input): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::in(['html', 'css', 'javascript'])],
            'code' => ['required', 'string', 'max:65536'],
            'position' => ['required', Rule::in(['head_end', 'body_start', 'body_end'])],
            'sort_order' => ['required', 'integer', 'between:-10000,10000'],
            'rules' => ['required', 'array:global,include,exclude'], 'rules.global' => ['required', 'boolean'],
            'rules.include' => ['present', 'array', 'max:100'], 'rules.exclude' => ['present', 'array', 'max:100'],
            'rules.include.*' => ['array:type,id,locale,group'], 'rules.exclude.*' => ['array:type,id,locale,group'],
        ])->validate();
        if (strlen($data['code']) > 65536 || preg_match('/<\?(?:php|=)?|\{\{|\{!!|@(?:php|include|extends|component|inject)\b/i', $data['code'])) abort(422, 'Server template syntax is not allowed.');
        if ($data['type'] === 'css') {
            abort_unless($data['position'] === 'head_end', 422, 'CSS belongs at the end of head.');
            abort_if(preg_match('/<\/?(?:style|script)\b/i', $data['code']), 422, 'CSS is a stylesheet body, without wrappers.');
        } elseif ($data['type'] === 'javascript') {
            abort_if(preg_match('/<\/?script\b/i', $data['code']), 422, 'JavaScript is a script body, without wrappers.');
        } else {
            abort_if(preg_match('/<\/?(?:html|head|body|script|style)\b/i', $data['code']), 422, 'HTML must be a fragment; CSS and JavaScript use their own types.');
        }
        $registry = app(CustomCodeDestinations::class);
        foreach (['include', 'exclude'] as $kind) {
            $data['rules'][$kind] = array_map(fn ($ref) => $registry->normalize($ref), $data['rules'][$kind]);
            foreach ($data['rules'][$kind] as $ref) abort_unless($registry->exists($ref), 422, 'Referenced destination does not exist in the selected locale.');
        }
        abort_unless($data['rules']['global'] || count($data['rules']['include']) > 0, 422, 'Select global coverage or exact included destinations.');
        $data['description'] = $data['description'] ?? '';
        return $data;
    }

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) ksort($value);
        foreach ($value as &$child) if (is_array($child)) $child = $this->canonical($child);
        return $value;
    }

    private function payloadFingerprint(array $payload): string
    {
        return hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR));
    }

    private function mutate(User $actor, string $action, ?int $id, array $input, callable $work): array
    {
        $this->authorize($actor);
        $data = Validator::make($input, ['operation_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_.:-]+$/'], 'expected_revision' => [$id ? 'required' : 'nullable', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $payload = $input; unset($payload['operation_key']);
        $canonical = function (array $value) use (&$canonical): array { if (! array_is_list($value)) ksort($value); foreach ($value as &$child) if (is_array($child)) $child = $canonical($child); return $value; };
        $hash = hash('sha256', json_encode([$action, $id, $canonical($payload)], JSON_THROW_ON_ERROR));
        return DB::transaction(function () use ($actor, $action, $id, $data, $hash, $work) {
            $lockedActor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorize($lockedActor);
            $receipt = DB::table('custom_code_receipts')->where(['user_id' => $actor->id, 'operation_key' => $data['operation_key']])->first();
            if ($receipt) { abort_unless(hash_equals($receipt->payload_hash, $hash), 409, 'Operation key already used with different input.'); return json_decode($receipt->result, true); }
            $row = $id ? DB::table('custom_code_snippets')->where('id', $id)->lockForUpdate()->first() : null;
            if ($id) { abort_unless($row, 404); $current = $this->revision($row); if (! hash_equals($current, $data['expected_revision'])) throw new RevisionConflict($data['expected_revision'], $current); }
            $result = $work($row);
            DB::table('custom_code_receipts')->insert(['user_id' => $actor->id, 'operation_key' => $data['operation_key'], 'payload_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            return $result;
        }, 3);
    }

    private function event(User $actor, int $id, string $action, ?int $version, array $detail = []): void
    {
        DB::table('custom_code_events')->insert(['snippet_id' => $id, 'user_id' => $actor->id, 'action' => $action, 'version_id' => $version, 'detail' => json_encode($detail), 'created_at' => now()]);
    }

    public function save(User $actor, array $input, ?int $id = null): array
    {
        return $this->mutate($actor, 'save', $id, $input, function ($row) use ($actor, $input, $id) {
            $payload = $this->normalize($input);
            $snippetId = $id ?? DB::table('custom_code_snippets')->insertGetId(['name' => $payload['name'], 'description' => $payload['description'], 'state' => 'draft', 'generation' => 0, 'created_at' => now(), 'updated_at' => now()]);
            $version = DB::table('custom_code_versions')->insertGetId(['snippet_id' => $snippetId, 'author_id' => $actor->id, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'fingerprint' => $this->payloadFingerprint($payload), 'created_at' => now()]);
            DB::table('custom_code_snippets')->where('id', $snippetId)->update(['name' => $payload['name'], 'description' => $payload['description'], 'generation' => ($row->generation ?? 0) + 1, 'updated_at' => now()]);
            $this->event($actor, $snippetId, 'save', $version);
            return $this->get($actor, $snippetId);
        });
    }

    public function activate(User $actor, int $id, array $input): array
    {
        Validator::make($input, ['version_id' => ['required', 'integer', 'min:1']])->validate();
        return $this->mutate($actor, 'activate', $id, $input, function ($row) use ($actor, $id, $input) {
            $version = DB::table('custom_code_versions')->where('snippet_id', $id)->where('id', $input['version_id'])->first(); abort_unless($version, 404);
            $payload = json_decode($version->payload, true); $this->normalize($payload);
            abort_unless(hash_equals($version->fingerprint, $this->payloadFingerprint($payload)), 409, 'Version integrity check failed.');
            DB::table('custom_code_snippets')->where('id', $id)->update(['active_version_id' => $version->id, 'state' => 'active', 'generation' => $row->generation + 1, 'updated_at' => now()]);
            $this->event($actor, $id, 'activate', $version->id, ['fingerprint' => $version->fingerprint]);
            return $this->get($actor, $id);
        });
    }

    public function deactivate(User $actor, int $id, array $input): array
    {
        return $this->mutate($actor, 'deactivate', $id, $input, function ($row) use ($actor, $id) {
            DB::table('custom_code_snippets')->where('id', $id)->update(['state' => 'inactive', 'generation' => $row->generation + 1, 'updated_at' => now()]);
            $this->event($actor, $id, 'deactivate', $row->active_version_id);
            return $this->get($actor, $id);
        });
    }

    public function evaluate(User $actor, array $input): array
    {
        $this->authorize($actor);
        Validator::make($input, ['target' => ['required', 'array'], 'version_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']])->validate();
        $registry = app(CustomCodeDestinations::class); $target = $registry->normalize($input['target']);
        abort_unless($registry->exists($target), 422, 'Destination no longer exists.');
        $evaluation = app(CustomCodeRenderer::class)->evaluate($target, $input['version_id'] ?? null);
        $total = count($evaluation['snippets']); $page = (int) ($input['page'] ?? 1); $size = (int) ($input['per_page'] ?? 20);
        $evaluation['snippets'] = array_slice($evaluation['snippets'], ($page - 1) * $size, $size);
        foreach ($evaluation['snippets'] as &$snippet) {
            $code = $snippet['payload']['code'];
            $snippet['code_read'] = ['bytes' => strlen($code), 'sha256' => hash('sha256', $code), 'recovery' => 'custom_code_get with id and version_id'];
            unset($snippet['payload']['code']);
        }
        return $evaluation + ['total' => $total, 'page' => $page, 'per_page' => $size, 'last_page' => max(1, (int) ceil($total / $size))];
    }
}
