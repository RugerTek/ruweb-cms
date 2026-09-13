<?php

namespace App\Models;

use App\Traits\RecordsContentRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingTranslation extends Model
{
    use RecordsContentRevisions;

    protected $fillable = ['setting_id', 'locale', 'value'];

    public function setting(): BelongsTo
    {
        return $this->belongsTo(Setting::class);
    }

    /** @return array{0: string, 1: ?int} */
    public function revisionSubject(): array
    {
        return [Setting::class, $this->setting_id];
    }
}
