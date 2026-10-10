<?php

namespace App\Models;

use App\Contracts\Editorial\Mediable;
use App\Contracts\Editorial\Previewable;
use App\Contracts\Editorial\Publishable;
use App\Contracts\Editorial\Seoable;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use App\Support\LinkDestination;
use App\Support\PageComposition;
use App\Support\SectionRichText;
use App\Support\SiteUrl;
use App\Traits\HasMedia;
use App\Traits\HasPublicationState;
use App\Traits\HasTranslations;
use App\Traits\RecordsContentRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\View;

class Page extends Model implements Mediable, Previewable, Publishable, Seoable
{
    use HasMedia, HasPublicationState, HasTranslations;
    use RecordsContentRevisions;

    protected static function booted(): void
    {
        static::saving(function ($model) {
            if ($model->exists && $model->isDirty()) {
                app(EditorialHistory::class)->capture($model->newQuery()->findOrFail($model->id));
            }
            if (EditorialActor::current()?->isEditor() && ($model->status === 'published' || $model->getOriginal('status') === 'published') && (! $model->exists || $model->isDirty())) {
                abort(403, __('editorial.publish_forbidden'));
            }
        });
        static::deleting(function ($model) {
            abort_if(EditorialActor::current()?->isEditor() && $model->status === 'published', 403, __('editorial.publish_forbidden'));
        });
        static::saved(fn ($model) => app(EditorialHistory::class)->capture($model));
    }

    protected $fillable = [
        'locale',
        'translation_group_id',
        'translation_status',
        'source_fingerprint',
        'source_field_hashes',
        'title',
        'slug',
        'template_key',
        'content_json',
        'status',
        'published_at',
        'expires_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'source_field_hashes' => 'array',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected function setContentJsonAttribute(mixed $value): void
    {
        $content = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($content)) {
            $this->attributes['content_json'] = json_encode($content);

            return;
        }

        $content = PageComposition::normalize($content);

        $schema = config('cms.templates.'.($this->template_key ?: 'default').'.schema', []);
        foreach ($schema as $path => $field) {
            if (isset($field['rich_text']) && is_string(data_get($content, $path))) {
                data_set($content, $path, (new SectionRichText($field['rich_text']))->sanitize(data_get($content, $path)));
            }
        }
        if (isset($content['sections']['cta'])) {
            $cta = &$content['sections']['cta'];
            if (($cta['button_page'] ?? null) === LinkDestination::CUSTOM) {
                $cta['button_page'] = null;
            }
            if (! empty($cta['button_page'])) {
                $cta['button_url'] = null;
            } elseif (isset($cta['button_url'])) {
                $cta['button_url'] = SiteUrl::toRelative(trim((string) $cta['button_url']));
            }
        }
        $this->attributes['content_json'] = json_encode($content);
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
        return $this->content_json['meta'] ?? [];
    }

    public function sections(): array
    {
        return $this->content_json['sections'] ?? [];
    }

    public function resolveTemplate(): string
    {
        $template = 'templates.'.$this->template_key;

        if (View::exists($template)) {
            return $template;
        }

        return 'templates.default';
    }

    public function url(): string
    {
        return $this->localizedUrl();
    }

    public function localizedUrl(): string
    {
        if ($this->translation_group_id && $this->translation_group_id === Setting::get('homepage_translation_group_id')) {
            return $this->isBaseLocale() ? '/' : '/'.$this->locale;
        }

        $path = '/'.ltrim($this->slug, '/');

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
        return $this->resolveTemplate();
    }

    public function previewData(): array
    {
        return ['page' => $this];
    }
}
