<?php

namespace App\Services\Content;

use App\Models\Locale;
use App\Models\User;
use App\Services\Editorial\EditorialActor;
use App\Services\Revisions\ContentRevisionLedger;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class LocaleOperations
{
    public function install(User $actor, string $code): Locale
    {
        abort_unless($actor->isAdmin(), 403);
        Validator::make(['code' => $code], ['code' => ['required', 'string', Rule::in(Locale::catalogCodes())]])->validate();

        return EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $code) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();

            return Locale::firstOrCreate(['code' => $code], ['name' => Locale::catalog()[$code], 'is_base' => false, 'is_active' => true, 'is_public' => false, 'sort_order' => (int) Locale::max('sort_order') + 1]);
        }, 3));
    }

    public function update(User $actor, array $input, bool $replace = false): void
    {
        abort_unless($actor->isAdmin(), 403);
        $data = Validator::make(['locales' => $input], ['locales' => ['required', 'array', 'max:50'], 'locales.*' => ['array:is_active,is_public'], 'locales.*.is_active' => ['sometimes', 'boolean'], 'locales.*.is_public' => ['sometimes', 'boolean']])->validate()['locales'];
        Validator::make(['codes' => array_keys($data)], ['codes.*' => [Rule::in(Locale::installedCodes())]])->validate();
        EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($data, $replace) {
            foreach (Locale::query()->orderBy('id')->lockForUpdate()->get() as $locale) {
                if ($locale->is_base) {
                    $locale->update(['is_active' => true, 'is_public' => true]);
                } elseif ($replace || isset($data[$locale->code])) {
                    $row = $data[$locale->code] ?? [];
                    $active = (bool) ($row['is_active'] ?? ($replace ? false : $locale->is_active));
                    $locale->update(['is_active' => $active, 'is_public' => $active && (bool) ($row['is_public'] ?? ($replace ? false : $locale->is_public))]);
                }
            }
        }, 3));
    }
}
