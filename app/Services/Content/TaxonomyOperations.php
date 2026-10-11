<?php

namespace App\Services\Content;

use App\Exceptions\RevisionConflict;
use App\Models\Locale;
use App\Models\Taxonomy;
use App\Models\User;
use App\Services\Editorial\EditorialActor;
use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Taxonomy writes shared by the panel and both bearer transports. */
final class TaxonomyOperations
{
    public function revision(Taxonomy $taxonomy): string
    {
        $attributes = $taxonomy->attributesToArray();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function create(User $actor, string $type, array $input): Taxonomy
    {
        Gate::forUser($actor)->authorize('create', Taxonomy::class);
        $this->assertType($type);
        $locale = $input['locale'] ?? Locale::baseCode();
        $data = Validator::make($input, $this->rules($type, $locale))->validate();
        $data['locale'] = $locale;

        return $this->transaction($actor, function () use ($data, $type) {
            $taxonomy = Taxonomy::create($data + ['type' => $type]);
            $taxonomy->source_fingerprint = $taxonomy->translatableFingerprint();
            $taxonomy->source_field_hashes = $taxonomy->translatableFieldFingerprints();
            $taxonomy->save();

            return $taxonomy->fresh();
        });
    }

    /** Partial update: omitted fields are kept. A translation saved here is not marked as reviewed. */
    public function update(User $actor, Taxonomy $taxonomy, array $input, ?string $expectedRevision = null, bool $reviewed = false): Taxonomy
    {
        Gate::forUser($actor)->authorize('update', $taxonomy);

        return $this->transaction($actor, function () use ($taxonomy, $input, $expectedRevision, $reviewed) {
            $taxonomy = Taxonomy::query()->lockForUpdate()->findOrFail($taxonomy->id);
            $this->check($taxonomy, $expectedRevision);
            $rules = array_map(fn (array $rule) => ['sometimes', ...array_slice($rule, 1)], $this->rules($taxonomy->type, $taxonomy->locale, $taxonomy));
            $data = Validator::make($input, $rules)->validate();
            $taxonomy->fill($data);
            if ($taxonomy->isBaseLocale()) {
                $taxonomy->source_fingerprint = $taxonomy->translatableFingerprint();
                $taxonomy->source_field_hashes = $taxonomy->translatableFieldFingerprints();
            } elseif ($reviewed) {
                $taxonomy->syncTranslationFromBase();
            }
            $taxonomy->save();

            return $taxonomy->fresh();
        });
    }

    public function translate(User $actor, Taxonomy $taxonomy, string $locale): Taxonomy
    {
        Gate::forUser($actor)->authorize('create', Taxonomy::class);
        if (! Locale::where('code', $locale)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['locale' => 'Choose an installed, active locale.']);
        }

        return $this->transaction($actor, function () use ($taxonomy, $locale) {
            if ($existing = $taxonomy->translations()->where('locale', $locale)->first()) {
                return $existing;
            }
            $base = $taxonomy->baseTranslation() ?: $taxonomy;
            $translation = $base->replicate(['slug']);
            $translation->locale = $locale;
            $translation->slug = $this->uniqueSlug($base->slug, $base->type, $locale);
            $parent = $base->parent_id ? $base->parent()->with('translations')->first() : null;
            $translation->parent_id = $parent?->translations->firstWhere('locale', $locale)?->id;
            $translation->translation_status = 'needs_review';
            $translation->source_fingerprint = $base->translatableFingerprint();
            $translation->source_field_hashes = $base->translatableFieldFingerprints();
            $translation->save();

            return $translation->fresh();
        });
    }

    /** Children move up one level, post assignments are removed; returns what was affected. */
    public function delete(User $actor, Taxonomy $taxonomy, ?string $expectedRevision = null): array
    {
        Gate::forUser($actor)->authorize('delete', $taxonomy);

        return $this->transaction($actor, function () use ($taxonomy, $expectedRevision) {
            $taxonomy = Taxonomy::query()->lockForUpdate()->findOrFail($taxonomy->id);
            $this->check($taxonomy, $expectedRevision);
            $warnings = app(ContentDeletion::class)->cleanUp($taxonomy);
            $children = $taxonomy->children()->pluck('id')->all();
            if ($children !== []) {
                $taxonomy->children()->update(['parent_id' => $taxonomy->parent_id]);
                $warnings[] = ['code' => 'children_moved_up', 'taxonomies' => $children, 'message' => 'Child terms now hang from the deleted term\'s parent.'];
            }
            $assigned = DB::table('taxables')->where('taxonomy_id', $taxonomy->id)->count();
            if ($assigned > 0) {
                $warnings[] = ['code' => 'assignments_removed', 'count' => $assigned, 'message' => 'The term was removed from the content that used it.'];
            }
            $taxonomy->delete();

            return $warnings;
        });
    }

    private function rules(string $type, string $locale, ?Taxonomy $taxonomy = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(Locale::catalogCodes()),
                Rule::unique('taxonomies')->where('type', $type)->where('locale', $locale)->ignore($taxonomy?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('taxonomies', 'id')->where('type', $type)->where('locale', $locale), Rule::notIn(array_filter([$taxonomy?->id]))],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, config('cms.taxonomy_types', []), true)) {
            throw ValidationException::withMessages(['type' => 'Unknown taxonomy type. Registered types: '.implode(', ', config('cms.taxonomy_types', [])).'.']);
        }
    }

    private function check(Taxonomy $taxonomy, ?string $expected): void
    {
        if ($expected !== null && ! hash_equals($current = $this->revision($taxonomy), $expected)) {
            throw new RevisionConflict($expected, $current);
        }
    }

    private function uniqueSlug(string $slug, string $type, string $locale): string
    {
        $candidate = $slug;
        for ($counter = 2; Taxonomy::where('type', $type)->where('locale', $locale)->where('slug', $candidate)->exists(); $counter++) {
            $candidate = $slug.'-'.$counter;
        }

        return $candidate;
    }

    private function transaction(User $actor, \Closure $callback): mixed
    {
        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction($callback, 3));
    }
}
