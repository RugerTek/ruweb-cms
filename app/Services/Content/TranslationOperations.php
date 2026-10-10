<?php

namespace App\Services\Content;

use App\Mcp\ContentResponse;
use App\Models\Locale;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\Editorial\EditorialActor;
use App\Services\Editorial\EditorialHistory;
use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Shared translation creation, source changes and field acknowledgements. */
final class TranslationOperations
{
    public function create(User $actor, Page|Post $source, string $locale): Model
    {
        $this->authorize($actor, $source);
        if ($source instanceof Post) {
            Gate::forUser($actor)->authorize('create', Post::class);
        }
        Validator::make(['locale' => $locale], ['locale' => ['required', Rule::in(Locale::where('is_active', true)->pluck('code')->all())]])->validate();
        abort_unless($source->isTranslationSchemaReady(), 422, 'This template/content type has no translation schema.');

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $source, $locale) {
            $source = $source->newQuery()->lockForUpdate()->findOrFail($source->id);
            $base = $source->baseTranslation() ?: $source;
            // The source lock serializes retries and protects group/locale uniqueness.
            $base = $base->newQuery()->lockForUpdate()->findOrFail($base->id);
            abort_unless($base->isTranslationSchemaReady(), 422, 'This template/content type has no translation schema.');
            if ($existing = $base->translations()->where('locale', $locale)->first()) {
                return $existing;
            }
            $translation = $base->replicate(['slug', 'published_at', 'expires_at']);
            $translation->locale = $locale;
            $slug = $base->slug;
            for ($counter = 2; $base->newQuery()->where('locale', $locale)->where('slug', $slug)->exists(); $counter++) {
                $slug = $base->slug.'-'.$counter;
            }
            $translation->slug = $slug;
            $translation->status = 'draft';
            $translation->translation_status = 'needs_review';
            $translation->source_fingerprint = $base->translatableFingerprint();
            $translation->source_field_hashes = $base->translatableFieldFingerprints();
            $translation->created_by = $actor->id;
            $translation->updated_by = $actor->id;
            $translation->save();
            foreach ($base->media()->get() as $media) {
                $translation->attachMedia($media->id, $media->pivot->collection, $media->pivot->order);
            }
            if ($base instanceof Post) {
                $ids = $base->taxonomies()->with('translations')->get()->map(fn ($term) => $term->translations->firstWhere('locale', $locale)?->id)->filter()->values()->all();
                $translation->taxonomies()->sync($ids);
            }
            app(EditorialHistory::class)->capture($translation->fresh());

            return $translation->fresh();
        }, 3));
    }

    public function changes(User $actor, Page|Post $model, array $input = []): array
    {
        $this->authorize($actor, $model);
        $options = Validator::make($input, ['field' => ['sometimes', 'string', Rule::in($model->staleTranslatableFields())], 'page' => ['sometimes', 'integer', 'min:1'], 'offset' => ['sometimes', 'integer', 'min:0'], 'length' => ['sometimes', 'integer', 'min:1', 'max:8192']])->validate();
        $allFields = array_values(array_filter($model->staleTranslatableFields(), fn ($field) => strlen($field) <= 500));
        $fields = isset($options['field']) ? [$options['field']] : array_slice($allFields, (($options['page'] ?? 1) - 1) * 100, 100);
        $changes = [];
        foreach ($fields as $field) {
            $change = app(EditorialHistory::class)->sourceChange($model, $field);
            foreach (['before', 'after'] as $side) {
                if (is_string($change[$side])) {
                    $offset = $options['offset'] ?? 0;
                    $length = isset($options['field']) ? ($options['length'] ?? 8192) : 256;
                    $total = mb_strlen($change[$side]);
                    $change[$side] = mb_substr($change[$side], $offset, $length);
                    $change[$side.'_range'] = ['offset' => $offset, 'length' => $length, 'total_characters' => $total, 'has_more' => $offset + $length < $total];
                }
            }
            foreach (['before', 'after'] as $side) {
                if (is_array($change[$side])) {
                    $change[$side] = ContentResponse::bounded($change[$side], 1024);
                }
            }
            $changes[$field] = $change;
        }

        return ['source_id' => $model->baseTranslation()?->id, 'source_fingerprint' => $model->baseTranslation()?->translatableFingerprint(), 'stale_fields' => $fields, 'changes' => $changes, 'current_page' => $options['page'] ?? 1, 'total_fields' => count($allFields), 'last_page' => max(1, (int) ceil(count($allFields) / 100)), 'has_more' => ! isset($options['field']) && ($options['page'] ?? 1) * 100 < count($allFields), 'omitted_oversized_fields' => count($model->staleTranslatableFields()) - count($allFields)];
    }

    public function acknowledge(User $actor, Page|Post $model, array $input): Model
    {
        $this->authorize($actor, $model);
        $data = Validator::make($input, ['field' => ['required', 'string', 'max:500'], 'base_hash' => ['required', 'string', 'size:64'], 'expected_revision' => ['nullable', 'string', 'size:64']])->validate();

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $model, $data) {
            $model = $model->newQuery()->lockForUpdate()->findOrFail($model->id);
            abort_unless($model->status === 'draft', 403, __('editorial.mcp_draft_required'));
            RevisionGuard::check($model, $data['expected_revision'] ?? null);
            abort_if($model->isBaseLocale(), 422, 'Only a translation can acknowledge source fields.');
            $base = $model->baseTranslation();
            abort_unless($base, 422, 'Base translation is missing.');
            $base = $base->newQuery()->lockForUpdate()->findOrFail($base->id);
            $hashes = $base->translatableFieldFingerprints();
            abort_unless(isset($hashes[$data['field']]) || array_key_exists($data['field'], $model->source_field_hashes ?? []), 422, 'Unknown source field.');
            $hash = $hashes[$data['field']] ?? $model::removedSourceFieldHash();
            abort_unless(hash_equals($hash, $data['base_hash']), 409, 'Source field changed; inspect translation changes and retry.');
            $model->source_field_hashes = array_replace($model->source_field_hashes ?? [], [$data['field'] => $hash]);
            if ($model->staleTranslatableFields() === []) {
                $model->source_fingerprint = $base->translatableFingerprint();
                $model->translation_status = null;
            }
            $model->updated_by = $actor->id;
            $model->save();
            app(EditorialHistory::class)->capture($model->fresh());

            return $model->fresh();
        }, 3));
    }

    private function authorize(User $actor, Model $model): void
    {
        if ($model instanceof Post) {
            Gate::forUser($actor)->authorize('update', $model);
        } else {
            abort_unless(in_array($actor->role, config('cms.roles', []), true), 403);
        }
    }
}
