<?php

namespace App\Traits;

use App\Models\Locale;
use App\Services\Content\ContentSchemaRegistry;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

trait HasTranslations
{
    protected static function bootHasTranslations(): void
    {
        static::creating(function ($model): void {
            if (empty($model->locale)) {
                $model->locale = Locale::baseCode();
            }

            if (empty($model->translation_group_id)) {
                $model->translation_group_id = (string) Str::uuid();
            }
        });
    }

    public function translations(): HasMany
    {
        return $this->hasMany(static::class, 'translation_group_id', 'translation_group_id');
    }

    public function hasPublicationState(): bool
    {
        return in_array(HasPublicationState::class, class_uses_recursive(static::class), true);
    }

    protected function findGroupTranslation(string $locale): ?static
    {
        if ($this->relationLoaded('translations')) {
            return $this->translations->firstWhere('locale', $locale);
        }

        return $this->translations()->where('locale', $locale)->first();
    }

    public function baseTranslation(): ?static
    {
        return $this->findGroupTranslation(Locale::baseCode());
    }

    public function publishedTranslationFor(string $locale): ?static
    {
        $query = $this->translations()->where('locale', $locale);

        if ($this->hasPublicationState()) {
            $query->published();
        }

        return $query->first();
    }

    public function availablePublishedTranslations()
    {
        $query = $this->translations()->whereIn('locale', Locale::publicCodes());

        if ($this->hasPublicationState()) {
            $query->published();
        }

        return $query->get();
    }

    public function isBaseLocale(): bool
    {
        return $this->locale === Locale::baseCode();
    }

    public function isTranslationSchemaReady(): bool
    {
        return app(ContentSchemaRegistry::class)->isTranslatable($this);
    }

    public function translatableFingerprint(): ?string
    {
        $registry = app(ContentSchemaRegistry::class);

        if (! $registry->isTranslatable($this)) {
            return null;
        }

        $payload = [
            'entity' => static::class,
            'fields' => $registry->extract($this, 'translatable'),
        ];

        if (property_exists($this, 'template_key') || isset($this->template_key)) {
            $payload['template_key'] = $this->template_key;
        }

        return hash('sha256', json_encode($this->sortForHash($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function translatableFieldFingerprints(): array
    {
        $registry = app(ContentSchemaRegistry::class);

        if (! $registry->isTranslatable($this)) {
            return [];
        }

        $hashes = [];

        foreach ($registry->extract($this, 'translatable') as $path => $value) {
            $hashes[$path] = hash('sha256', json_encode($this->sortValueForHash($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        ksort($hashes);

        return $hashes;
    }

    public function staleTranslatableFields(): array
    {
        if ($this->isBaseLocale()) {
            return [];
        }

        $base = $this->baseTranslation();
        if (! $base) {
            return [];
        }

        $currentHashes = $base->translatableFieldFingerprints();
        if ($currentHashes === [] && empty($this->source_field_hashes)) {
            return [];
        }

        $storedHashes = is_array($this->source_field_hashes) ? $this->source_field_hashes : null;
        if ($storedHashes === null) {
            return $this->source_fingerprint === $base->translatableFingerprint()
                ? []
                : array_keys($currentHashes);
        }

        $paths = array_unique(array_merge(array_keys($currentHashes), array_keys($storedHashes)));

        return array_values(array_filter($paths, fn (string $path): bool => ($storedHashes[$path] ?? null) !== ($currentHashes[$path] ?? self::removedSourceFieldHash())));

    }

    public static function removedSourceFieldHash(): string
    {
        return hash('sha256', 'ruweb:removed-source-field');
    }

    /**
     * Re-sync a translation against its base: stamp the current base fingerprint
     * and clear the editorial review flag. Saving a translation counts as review.
     */
    public function syncTranslationFromBase(): void
    {
        $base = $this->baseTranslation() ?: $this;

        $this->source_fingerprint = $base->translatableFingerprint();
        $this->source_field_hashes = $base->translatableFieldFingerprints();
        $this->translation_status = null;
    }

    public function derivedTranslationState(?string $locale = null): string
    {
        $translation = $locale && $locale !== $this->locale
            ? $this->findGroupTranslation($locale)
            : $this;

        if (! $translation) {
            return 'missing';
        }

        $hasState = $translation->hasPublicationState();

        if ($hasState && ($translation->status ?? null) === 'draft') {
            return 'draft';
        }

        $base = $this->findGroupTranslation(Locale::baseCode()) ?: $translation;
        $currentFingerprint = $base->translatableFingerprint();

        if ($currentFingerprint && $translation->source_fingerprint && $translation->source_fingerprint !== $currentFingerprint) {
            return 'outdated';
        }

        if ($translation->translation_status === 'needs_review') {
            return 'needs_review';
        }

        if (! $hasState) {
            return 'published';
        }

        return ($translation->status ?? null) === 'published' ? 'published' : 'draft';
    }

    private function sortForHash(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortForHash($item);
            }
        }

        return $value;
    }

    private function sortValueForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return $this->sortForHash($value);
    }
}
