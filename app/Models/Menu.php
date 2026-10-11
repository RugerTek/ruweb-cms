<?php

namespace App\Models;

use App\Traits\RecordsContentRevisions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Menu extends Model
{
    use RecordsContentRevisions;

    protected $fillable = ['locale', 'name', 'slug', 'location'];

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('order');
    }

    public function rootItems(): HasMany
    {
        return $this->hasMany(MenuItem::class)->whereNull('parent_id')->orderBy('order');
    }

    /**
     * $public drops items whose target is missing or not publicly visible (drafts, scheduled, expired), with their
     * branch: public pages never link to unpublished content. Editors and MCP read the full tree.
     */
    public function tree(bool $public = false): Collection
    {
        $items = $this->items()->with('linkable')->get();
        if ($public) {
            $items = $items->reject(fn (MenuItem $item) => $item->type !== 'custom_link'
                && (! $item->linkable || (method_exists($item->linkable, 'isPublished') && ! $item->linkable->isPublished())));
        }
        $grouped = $items->groupBy('parent_id');

        $buildTree = function ($parentId) use (&$buildTree, $grouped) {
            return ($grouped[$parentId] ?? collect())->map(function ($item) use ($buildTree) {
                $item->setRelation('children', $buildTree($item->id));
                return $item;
            });
        };

        return $buildTree(null);
    }

    public static function findBySlug(string $slug): ?static
    {
        return static::where('slug', $slug)
            ->where('locale', app()->getLocale())
            ->first()
            ?: static::where('slug', $slug)
                ->where('locale', Locale::baseCode())
                ->first();
    }

    public static function findByLocation(string $location): ?static
    {
        return static::where('location', $location)
            ->where('locale', app()->getLocale())
            ->first()
            ?: static::where('location', $location)
                ->where('locale', Locale::baseCode())
                ->first();
    }
}
