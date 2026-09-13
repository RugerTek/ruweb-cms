<?php

namespace App\Services\Revisions;

use App\Models\ContentRevision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Append-only ledger of content writes.
 *
 * Every tracked model write records one row whose hash is unique per write.
 * A snapshot carries the whole table, so a destination can check whether its
 * own revisions are all contained in the snapshot (safe fast-forward) or
 * whether it holds changes the snapshot lacks (divergence). Containment is a
 * set membership test, so concurrent writes and pruning never break it.
 */
class ContentRevisionLedger
{
    public const TABLE = 'content_revisions';

    /** Coalesces repeated writes to the same subject within one request. */
    protected array $recordedThisRequest = [];

    protected bool $enabled = true;

    protected ?bool $available = null;

    public function record(string $entityType, ?int $entityId, string $action, ?int $userId = null): ?ContentRevision
    {
        if (! $this->enabled || ! $this->available()) {
            return null;
        }

        $key = $entityType.'#'.($entityId ?? '-').'#'.$action;

        if (isset($this->recordedThisRequest[$key])) {
            return null;
        }

        $this->recordedThisRequest[$key] = true;
        $parent = $this->head()?->hash;

        $revision = ContentRevision::query()->create([
            'hash' => hash('sha256', implode('|', [$parent ?? '', $entityType, $entityId ?? '', $action, microtime(true), Str::random(16)])),
            'parent_hash' => $parent,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'user_id' => $userId,
            'created_at' => Carbon::now(),
        ]);

        return $revision;
    }

    /** Run a callback without recording anything, e.g. seeders or bulk imports. */
    public function withoutRecording(callable $callback): mixed
    {
        $previous = $this->enabled;
        $this->enabled = false;

        try {
            return $callback();
        } finally {
            $this->enabled = $previous;
        }
    }

    public function head(): ?ContentRevision
    {
        return $this->available() ? ContentRevision::query()->orderByDesc('id')->first() : null;
    }

    public function count(): int
    {
        return $this->available() ? ContentRevision::query()->count() : 0;
    }

    public function available(): bool
    {
        return $this->available ??= Schema::hasTable(self::TABLE);
    }

    /**
     * Compare this installation's revisions against a set of hashes carried
     * by a snapshot.
     *
     * @param  array<string, true>  $snapshotHashes  hash => true
     * @return array{total: int, contained: int, diverged: int, changes: array<int, array<string, mixed>>}
     */
    public function compareWith(array $snapshotHashes, int $listLimit = 50): array
    {
        $total = 0;
        $contained = 0;
        $diverged = [];

        if ($this->available()) {
            foreach (ContentRevision::query()->orderByDesc('id')->cursor() as $revision) {
                $total++;

                if (isset($snapshotHashes[$revision->hash])) {
                    $contained++;

                    continue;
                }

                $diverged[] = $revision;
            }
        }

        $listed = array_slice($diverged, 0, $listLimit);
        $users = DB::table('users')
            ->whereIn('id', array_filter(array_map(fn (ContentRevision $r) => $r->user_id, $listed)))
            ->pluck('email', 'id');

        return [
            'total' => $total,
            'contained' => $contained,
            'diverged' => count($diverged),
            'changes' => array_map(fn (ContentRevision $r): array => [
                'hash' => $r->shortHash(),
                'entity' => class_basename($r->entity_type).($r->entity_id ? '#'.$r->entity_id : ''),
                'action' => $r->action,
                'user' => $r->user_id ? ($users[$r->user_id] ?? ('#'.$r->user_id)) : null,
                'at' => $r->created_at?->toIso8601String(),
            ], $listed),
        ];
    }

    /**
     * Delete revisions older than `$keepDays`, always keeping the newest
     * `$keepMin` rows. Returns the number of rows removed.
     */
    public function prune(int $keepDays = 90, int $keepMin = 10000): int
    {
        if (! $this->available()) {
            return 0;
        }

        $floorId = ContentRevision::query()->orderByDesc('id')->skip(max(0, $keepMin))->value('id');

        if ($floorId === null) {
            return 0;
        }

        return ContentRevision::query()
            ->where('id', '<=', $floorId)
            ->where('created_at', '<', Carbon::now()->subDays(max(0, $keepDays)))
            ->delete();
    }
}
