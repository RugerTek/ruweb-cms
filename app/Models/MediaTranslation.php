<?php

namespace App\Models;

use App\Traits\RecordsContentRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaTranslation extends Model
{
    use RecordsContentRevisions;

    protected $fillable = ['media_id', 'locale', 'alt', 'title'];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function revisionSubject(): array
    {
        return [Media::class, $this->media_id];
    }
}
