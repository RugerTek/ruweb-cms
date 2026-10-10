<?php

namespace App\Services\Content;

use App\Exceptions\RevisionConflict;
use App\Services\Editorial\EditorialHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;

final class RevisionGuard
{
    public static function check(Model $model, ?string $expected): void
    {
        if ($expected === null) {
            return; // Compatible clients may omit it; new clients should always send it.
        }
        Validator::make(['expected_revision' => $expected], ['expected_revision' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]+$/']])->validate();
        $current = app(EditorialHistory::class)->fingerprint($model);
        if (! hash_equals($current, $expected)) {
            throw new RevisionConflict($expected, $current);
        }
    }
}
