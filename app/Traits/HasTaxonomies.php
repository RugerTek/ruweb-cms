<?php

namespace App\Traits;

use App\Models\Post;
use App\Models\Taxonomy;
use App\Services\Editorial\EditorialHistory;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasTaxonomies
{
    public function taxonomies(): MorphToMany
    {
        return $this->morphToMany(Taxonomy::class, 'taxable')
            ->withTimestamps();
    }

    public function taxonomiesByType(string $type): MorphToMany
    {
        return $this->taxonomies()->where('type', $type);
    }

    public function categories(): MorphToMany
    {
        return $this->taxonomiesByType('category');
    }

    /**
     * Sync taxonomy terms of a given type without affecting other types.
     */
    public function syncTaxonomies(array $ids, string $type): void
    {
        $currentIds = $this->taxonomiesByType($type)->pluck('taxonomies.id');
        $this->taxonomies()->detach($currentIds);

        if (! empty($ids)) {
            $this->taxonomies()->attach($ids);
        }
        if ($this instanceof Post) {
            app(EditorialHistory::class)->capture($this);
        }
    }
}
