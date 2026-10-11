<?php

namespace App\Models;

use App\Contracts\Editorial\Mediable;
use App\Contracts\Editorial\Previewable;
use App\Contracts\Editorial\Publishable;
use App\Contracts\Editorial\Seoable;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use App\Support\RichTextSanitizer;
use App\Traits\ComparesJsonByContent;
use App\Traits\HasMedia;
use App\Traits\HasPublicationState;
use App\Traits\HasTaxonomies;
use App\Traits\HasTranslations;
use App\Traits\RecordsContentRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model implements Mediable, Previewable, Publishable, Seoable
{
    use ComparesJsonByContent, HasMedia, HasPublicationState, HasTaxonomies, HasTranslations;
    use RecordsContentRevisions;

    protected static function booted(): void
    {
        static::saving(function ($model) {
            if ($model->exists && $model->isDirty()) {
                app(EditorialHistory::class)->captureBefore($model->newQuery()->findOrFail($model->id));
            }
            if (EditorialActor::current()?->isEditor() && ($model->status === 'published' || $model->getOriginal('status') === 'published') && (! $model->exists || $model->isDirty())) {
                abort(403, __('editorial.publish_forbidden'));
            }
        });
        static::deleting(function ($model) {
            abort_if(EditorialActor::current()?->isEditor() && $model->status === 'published', 403, __('editorial.publish_forbidden'));
        });
        static::saved(fn ($model) => app(EditorialHistory::class)->captureAfter($model));
    }

    protected $fillable = [
        'locale',
        'translation_group_id',
        'translation_status',
        'source_fingerprint',
        'source_field_hashes',
        'title',
        'slug',
        'excerpt',
        'content',
        'meta_json',
        'status',
        'published_at',
        'expires_at',
        'created_by',
        'updated_by',
    ];

    /**
     * Rich text is sanitised on the way in, so every writer — admin form, MCP API,
     * seeders — stores the same allowlisted markup.
     */
    protected function setContentAttribute(?string $value): void
    {
        $this->attributes['content'] = app(RichTextSanitizer::class)->sanitize($value);
    }

    protected function casts(): array
    {
        return [
            'meta_json' => 'array',
            'source_field_hashes' => 'array',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function meta(): array
    {
        return $this->meta_json ?? [];
    }

    public function url(): string
    {
        return $this->localizedUrl();
    }

    public function localizedUrl(): string
    {
        $path = '/blog/'.ltrim($this->slug, '/');

        if ($this->isBaseLocale()) {
            return $path;
        }

        return '/'.$this->locale.$path;
    }

    public function seoTitleFallback(): ?string
    {
        return $this->title;
    }

    public function previewView(): string
    {
        return 'blog.show';
    }

    public function previewData(): array
    {
        return [
            'post' => $this,
            'page' => $this,
        ];
    }
}
