<?php

namespace App\Models;

use App\Traits\RecordsContentRevisions;
use App\Traits\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Taxonomy extends Model
{
    use RecordsContentRevisions;

    use HasTranslations;

    protected $fillable = [
        'locale',
        'translation_group_id',
        'translation_status',
        'source_fingerprint',
        'source_field_hashes',
        'name',
        'slug',
        'type',
        'parent_id',
        'description',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'source_field_hashes' => 'array',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Taxonomy::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Taxonomy::class, 'parent_id')->orderBy('order');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('name');
    }
}
