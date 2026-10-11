<?php

namespace App\Services\Editorial;

use App\Models\Page;
use App\Models\Post;
use App\Services\Content\ContentSchemaRegistry;
use App\Support\PageComposition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Full restorable versions; intentionally independent from the snapshot hash ledger. */
class EditorialHistory
{
    /** Operation nesting depth; while open, model hooks defer post-write captures to one final version. */
    private static int $depth = 0;

    /** @var array<string, true> records whose pre-write baseline was already taken in this operation */
    private static array $touched = [];

    /** @var array<string, class-string<Model>> records written in this operation, captured once on close */
    private static array $pending = [];

    /**
     * One editorial operation yields at most one baseline and one resulting version per record, however many
     * internal saves, media or taxonomy writes it performs. Run inside the operation's transaction so the final
     * capture commits or rolls back with the content.
     */
    public function operation(callable $callback): mixed
    {
        if (self::$depth === 0) {
            self::$touched = self::$pending = [];
        }
        self::$depth++;
        try {
            $result = $callback();
            if (self::$depth === 1) {
                foreach (self::$pending as $key => $class) {
                    $record = $class::query()->find((int) substr($key, strrpos($key, '#') + 1));
                    if ($record) {
                        $this->capture($record);
                    }
                }
            }

            return $result;
        } finally {
            if (--self::$depth === 0) {
                self::$touched = self::$pending = [];
            }
        }
    }

    /** Pre-write state from a model hook: taken once per record and operation, never an intermediate state. */
    public function captureBefore(Model $model): void
    {
        if (self::$depth > 0) {
            $key = $model->getTable().'#'.$model->getKey();
            if (isset(self::$touched[$key])) {
                return;
            }
            self::$touched[$key] = true;
        }
        $this->capture($model);
    }

    /**
     * Post-write state from a model hook: deferred to the end of an open operation. Eloquent fires `saved` even
     * when nothing changed; such a save writes nothing, so it must not stop the next real write's baseline.
     */
    public function captureAfter(Model $model, ?bool $written = null): void
    {
        if (self::$depth === 0) {
            $this->capture($model);

            return;
        }
        $key = $model->getTable().'#'.$model->getKey();
        if ($written ?? ($model->wasRecentlyCreated || $model->wasChanged())) {
            self::$touched[$key] = true;
        }
        self::$pending[$key] = $model::class;
    }

    public function model(string $type, int $id): Model
    {
        abort_unless(in_array($type, ['pages', 'posts']), 404);

        return ($type === 'pages' ? Page::class : Post::class)::findOrFail($id);
    }

    public function query(Model $model, string $table = 'editorial_versions')
    {
        return DB::table($table)->where('content_type', $model->getTable())->where('content_id', $model->id);
    }

    public function payload(Model $model): array
    {
        $attributes = $model->attributesToArray();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
        $media = $model->media()->get()->map(fn ($m) => ['id' => $m->id, 'collection' => $m->pivot->collection, 'order' => $m->pivot->order])->all();

        return ['attributes' => $attributes, 'media' => $media, 'taxonomies' => method_exists($model, 'taxonomies') ? $model->taxonomies()->pluck('taxonomies.id')->all() : []];
    }

    public function fingerprint(Model $model): string
    {
        return hash('sha256', json_encode($this->payload($model), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function capture(Model $model): void
    {
        $payload = $this->payload($model);
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($this->query($model)->where('is_proposal', false)->latest('id')->value('fingerprint') === $hash) {
            return;
        }
        $this->query($model)->insert(['content_type' => $model->getTable(), 'content_id' => $model->id, 'user_id' => EditorialActor::current()?->id,
            'payload' => json_encode($payload), 'fingerprint' => $hash, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function stage(Model $model, array $data): int
    {
        $payload = $this->payload($model);
        $featured = $data['featured_image'] ?? null;
        $categories = $data['categories'] ?? null;
        $attributes = array_intersect_key($data, array_flip($model->getFillable()));
        if (isset($attributes['content_json'])) {
            $existing = $model->content_json ?? [];
            $submitted = $attributes['content_json'];
            foreach ($submitted['sections'] ?? [] as $key => $section) {
                $submitted['sections'][$key] = array_replace($existing['sections'][$key] ?? [], $section);
            }
            $submitted['sections'] = array_replace($existing['sections'] ?? [], $submitted['sections'] ?? []);
            if (isset($submitted['meta'])) {
                $submitted['meta'] = array_replace($existing['meta'] ?? [], $submitted['meta']);
            }
            $attributes['content_json'] = array_replace($existing, $submitted);
        }
        $proposal = $model->newInstance(array_replace($payload['attributes'], $attributes));
        $proposal->status = 'draft';
        $payload['attributes'] = $proposal->attributesToArray();
        if (array_key_exists('featured_image', $data)) {
            $payload['media'] = array_values(array_filter($payload['media'], fn ($m) => $m['collection'] !== 'featured_image'));
        }
        if ($featured) {
            $payload['media'][] = ['id' => (int) $featured, 'collection' => 'featured_image', 'order' => 0];
        }
        if ($categories !== null) {
            $payload['taxonomies'] = array_merge(DB::table('taxonomies')->whereIn('id', $payload['taxonomies'])->where('type', '!=', 'category')->pluck('id')->all(), $categories);
        }

        return $this->storeProposal($model, $payload);
    }

    public function storeProposal(Model $model, array $payload): int
    {
        if ($model instanceof Page && is_array($payload['attributes']['content_json'] ?? null)) {
            $payload['attributes']['content_json'] = PageComposition::normalize($payload['attributes']['content_json']);
            PageComposition::validateMedia($payload['attributes']['content_json']);
        }

        return $this->query($model)->insertGetId([
            'content_type' => $model->getTable(), 'content_id' => $model->id,
            'user_id' => EditorialActor::current()?->id, 'is_proposal' => true,
            'base_fingerprint' => $this->fingerprint($model),
            'payload' => json_encode($payload),
            'fingerprint' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function restore(Model $model, int $version, bool $asDraft = true): void
    {
        DB::transaction(function () use ($model, $version, $asDraft) {
            $model = $model->newQuery()->lockForUpdate()->findOrFail($model->id);
            $row = $this->query($model)->where('id', $version)->first();
            abort_unless($row, 404);
            $this->capture($model);
            $payload = json_decode($row->payload, true);
            $attributes = array_intersect_key($payload['attributes'], array_flip($model->getFillable()));
            if ($model instanceof Page && is_array($attributes['content_json'] ?? null)) {
                $attributes['content_json'] = PageComposition::normalize($attributes['content_json']);
                PageComposition::validateMedia($attributes['content_json']);
            }
            // Restoring always creates a draft: a historical publication is never silently republished.
            $attributes['status'] = $asDraft ? 'draft' : $model->status;
            $attributes['updated_by'] = EditorialActor::current()?->id;
            $model->fill($attributes)->save();
            $model->media()->detach();
            foreach ($payload['media'] as $media) {
                if (DB::table('media')->where('id', $media['id'])->exists()) {
                    $model->attachMedia($media['id'], $media['collection'], $media['order']);
                }
            }
            if (method_exists($model, 'taxonomies')) {
                $model->taxonomies()->sync(DB::table('taxonomies')->whereIn('id', $payload['taxonomies'])->pluck('id')->all());
            }
            $this->capture($model->fresh());
        });
    }

    /** Locate the source value actually acknowledged by this translation. */
    public function sourceChange(Model $translation, string $field): array
    {
        $base = $translation->baseTranslation();
        $current = app(ContentSchemaRegistry::class)->extract($base, 'translatable');
        $hash = $translation->source_field_hashes[$field] ?? null;
        $before = __('editorial.previous_unknown');
        if ($hash) {
            foreach ($this->query($base)->latest('id')->cursor() as $version) {
                $payload = json_decode($version->payload, true);
                $historical = $base->newInstance($payload['attributes']);
                $values = app(ContentSchemaRegistry::class)->extract($historical, 'translatable');
                if (($historical->translatableFieldFingerprints()[$field] ?? null) === $hash) {
                    $before = $values[$field] ?? null;
                    break;
                }
            }
        }

        return ['before' => $before, 'after' => $current[$field] ?? __('editorial.removed'), 'hash' => $base->translatableFieldFingerprints()[$field] ?? $base::removedSourceFieldHash()];
    }

    public function diff(array $before, array $after, string $prefix = ''): array
    {
        $diff = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $a = $before[$key] ?? null;
            $b = $after[$key] ?? null;
            if (is_array($a) && is_array($b)) {
                $diff = array_merge($diff, $this->diff($a, $b, $path));
            } elseif ($a !== $b) {
                $diff[$path] = ['before' => $a, 'after' => $b];
            }
        }

        return $diff;
    }
}
