<?php

namespace App\Services\Content;

use App\Models\User;
use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** A creation and its receipt commit together. The actor lock serializes concurrent retries. */
final class OperationReceipt
{
    public function run(User $actor, mixed $key, string $operation, array $payload, callable $work): Model
    {
        abort_unless(in_array($actor->role, config('cms.roles', []), true), 403);
        if ($key === null) {
            return $work();
        }
        Validator::make(['operation_key' => $key], ['operation_key' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_.:-]+$/']])->validate();
        unset($payload['operation_key']);
        $hash = hash('sha256', json_encode($this->canonical($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return app(ContentRevisionLedger::class)->transaction(function () use ($actor, $key, $operation, $hash, $work) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $query = DB::table('mcp_operation_receipts')->where(['user_id' => $actor->id, 'operation' => $operation, 'operation_key' => $key]);
            if ($receipt = $query->first()) {
                abort_unless(hash_equals($receipt->payload_hash, $hash), 409, 'Operation key already used with different input. Retry the original input or choose a new key.');
                abort_unless($receipt->model_id, 409, 'Operation result is unavailable.');

                return $receipt->model_type::findOrFail($receipt->model_id);
            }
            $result = $work();
            DB::table('mcp_operation_receipts')->insert(['user_id' => $actor->id, 'operation' => $operation, 'operation_key' => $key, 'payload_hash' => $hash,
                'model_type' => $result::class, 'model_id' => $result->getKey(), 'created_at' => now(), 'updated_at' => now()]);

            return $result;
        }, 3);
    }

    private function canonical(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }

        return $data;
    }
}
