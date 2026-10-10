<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class AdminBar
{
    /** @return list<array{label:string,href:string}> */
    public static function createLinks(User $user, string $locale, string $returnUrl): array
    {
        if (! app(AdminCapabilities::class)->liveEditor() || ! in_array($user->role, config('cms.roles', []), true)) {
            return [];
        }

        $links = [
            ['label' => trans('admin.content_type_page', [], $locale), 'href' => route('admin.pages.create', ['return' => $returnUrl])],
        ];

        foreach (config('cms.collections', []) as $key => $collection) {
            if (! Route::has($collection['create_route']) || ! Gate::forUser($user)->allows('create', $collection['model'])) {
                continue;
            }

            $label = trans($collection['label'], [], $locale);
            $links[] = [
                'label' => $label !== $collection['label'] ? $label : Str::headline(Str::singular($key)),
                'href' => route($collection['create_route'], ['return' => $returnUrl]),
            ];
        }

        return $links;
    }
}
