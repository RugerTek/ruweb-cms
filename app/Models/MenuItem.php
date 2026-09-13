<?php

namespace App\Models;

use App\Traits\RecordsContentRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MenuItem extends Model
{
    use RecordsContentRevisions;

    protected $fillable = [
        'menu_id', 'parent_id', 'label', 'type',
        'linkable_type', 'linkable_id', 'url', 'target', 'translation_status', 'order',
    ];

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'parent_id')->orderBy('order');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function resolveUrl(): string
    {
        if ($this->type === 'custom_link') {
            return $this->url ?? '#';
        }

        if ($this->linkable) {
            return match ($this->type) {
                'page'     => $this->linkable->url(),
                'post'     => $this->linkable->url(),
                'taxonomy' => $this->linkable->locale === Locale::baseCode()
                    ? route('blog.index', ['category' => $this->linkable->slug])
                    : route('localized.blog.index', ['locale' => $this->linkable->locale, 'category' => $this->linkable->slug]),
                default    => '#',
            };
        }

        return '#';
    }

    /** @return array{0: string, 1: ?int} */
    public function revisionSubject(): array
    {
        return [Menu::class, $this->menu_id];
    }
}
