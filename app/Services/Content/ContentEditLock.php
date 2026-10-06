<?php

namespace App\Services\Content;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentEditLock
{
    public const TTL_SECONDS = 120;

    public const HEARTBEAT_SECONDS = 30;

    // Serialize acquisition, renewal, release and the entire save against the same
    // content row, including the first acquisition (when no lease row exists yet).
    public function synchronized(Model $content, Closure $callback): mixed
    {
        return DB::transaction(function () use ($content, $callback) {
            $content->newQuery()->whereKey($content->getKey())->lockForUpdate()->firstOrFail();
            $content->refresh();

            return $callback();
        }, 3);
    }

    public function query(Model $content): Builder
    {
        return DB::table('content_edit_locks')
            ->where('content_type', $content->getMorphClass())
            ->where('content_id', $content->getKey());
    }

    public function active(Model $content): ?object
    {
        return $this->query($content)->where('expires_at', '>', now())->first();
    }

    public function sameSession(object $lock, Request $request): bool
    {
        return (int) $lock->user_id === (int) $request->user()->id
            && hash_equals($lock->session_hash, hash('sha256', $request->session()->getId()));
    }

    public function owns(?object $lock, Request $request): bool
    {
        $token = $request->input('_editor_lock');

        return $lock !== null && is_string($token) && $this->sameSession($lock, $request)
            && hash_equals($lock->token, $token);
    }

    // Called only while synchronized. Reopening in the same session rotates the
    // token: a late release or save from the old document cannot affect the new one.
    public function acquire(Model $content, Request $request): array
    {
        $token = (string) Str::uuid();
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);
        DB::table('content_edit_locks')->updateOrInsert([
            'content_type' => $content->getMorphClass(),
            'content_id' => $content->getKey(),
        ], [
            'user_id' => $request->user()->id,
            'user_name' => $request->user()->name,
            'session_hash' => hash('sha256', $request->session()->getId()),
            'token' => $token,
            'expires_at' => $expiresAt,
        ]);

        $type = $content->getTable();

        return [
            'token' => $token,
            'heartbeatUrl' => route('admin.editor-locks.heartbeat', [$type, $content->getKey()]),
            'releaseUrl' => route('admin.editor-locks.release', [$type, $content->getKey()]),
            'heartbeatSeconds' => self::HEARTBEAT_SECONDS,
            'ttlSeconds' => self::TTL_SECONDS,
            'expiresAt' => $expiresAt->getTimestampMs(),
            'lostMessage' => __('admin.editor_lock_lost'),
        ];
    }

    public function renew(Model $content): int
    {
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);
        $this->query($content)->update(['expires_at' => $expiresAt]);

        return $expiresAt->getTimestampMs();
    }
}
