<?php

namespace App\Traits;

use App\Models\Media;
use App\Models\Page;
use App\Models\Post;
use App\Services\Editorial\EditorialHistory;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasMedia
{
    public function media(): MorphToMany
    {
        return $this->morphToMany(Media::class, 'mediable')
            ->withPivot('collection', 'order')
            ->withTimestamps()
            ->orderBy('mediables.order');
    }

    public function mediaByCollection(string $collection): MorphToMany
    {
        return $this->media()->wherePivot('collection', $collection);
    }

    public function attachMedia(int $mediaId, ?string $collection = null, int $order = 0): void
    {
        $this->media()->attach($mediaId, [
            'collection' => $collection,
            'order' => $order,
        ]);
        if ($this instanceof Page || $this instanceof Post) {
            app(EditorialHistory::class)->capture($this);
        }
    }

    public function featuredImage(): ?Media
    {
        if ($this->relationLoaded('media')) {
            return $this->media->first(fn ($item) => $item->pivot->collection === 'featured_image');
        }

        return $this->mediaByCollection('featured_image')->first();
    }
}
