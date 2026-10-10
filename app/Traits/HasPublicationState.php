<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait HasPublicationState
{
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->where('published_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->published_at && $this->published_at->lte(now()) && (! $this->expires_at || $this->expires_at->gt(now()));
    }
}
