<?php

namespace App\Services\Content;

use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Model;

/**
 * What deleting an editorial record leaves behind, handled the same way for every transport: menu links to it
 * are removed (their children move up one level) and surviving translations are reported, never deleted.
 */
final class ContentDeletion
{
    /** Run inside the deletion transaction, before the record itself is deleted. */
    public function cleanUp(Model $model): array
    {
        $warnings = [];
        $items = MenuItem::query()->where('linkable_type', $model::class)->where('linkable_id', $model->getKey())->get();
        foreach ($items as $item) {
            MenuItem::query()->where('parent_id', $item->id)->update(['parent_id' => $item->parent_id]);
            $item->delete();
        }
        if ($items->isNotEmpty()) {
            $warnings[] = ['code' => 'menu_items_removed', 'menu_items' => $items->map(fn (MenuItem $item) => ['id' => $item->id, 'menu_id' => $item->menu_id, 'label' => $item->label])->values()->all(),
                'message' => 'Menu links to the deleted record were removed; their children moved up one level.'];
        }
        if (method_exists($model, 'translations')) {
            $translations = $model->translations()->whereKeyNot($model->getKey())->get(['id', 'locale']);
            if ($translations->isNotEmpty()) {
                $warnings[] = ['code' => 'translations_kept', 'translations' => $translations->map(fn (Model $t) => ['id' => $t->getKey(), 'locale' => $t->locale])->values()->all(),
                    'message' => 'Translations of the deleted record were kept; delete them separately if they are no longer wanted.'];
            }
        }

        return $warnings;
    }
}
