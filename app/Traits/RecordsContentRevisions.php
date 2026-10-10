<?php

namespace App\Traits;

use App\Services\Editorial\EditorialActor;
use App\Services\Revisions\ContentRevisionLedger;

/**
 * Append a content revision on every create, update and delete.
 *
 * Models that are details of a parent (menu items, setting translations)
 * override `revisionSubject()` so a burst of writes coalesces into one row
 * per parent per request.
 */
trait RecordsContentRevisions
{
    public static function bootRecordsContentRevisions(): void
    {
        foreach (['created', 'updated', 'deleted'] as $event) {
            static::$event(function ($model) use ($event): void {
                [$type, $id] = $model->revisionSubject();

                app(ContentRevisionLedger::class)->record($type, $id, $event, EditorialActor::current()?->id);
            });
        }
    }

    /** @return array{0: string, 1: ?int} */
    public function revisionSubject(): array
    {
        return [static::class, $this->getKey()];
    }
}
