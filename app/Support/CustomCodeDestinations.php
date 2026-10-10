<?php

namespace App\Support;

use App\Models\Locale;
use App\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Versioned registry: user input never selects a PHP class or a URL pattern. */
final class CustomCodeDestinations
{
    public function models(): array
    {
        $models = ['pages' => Page::class];
        foreach (config('cms.collections', []) as $type => $entry) {
            if (($entry['custom_code_public'] ?? false) && is_subclass_of($entry['model'], Model::class)) $models[$type] = $entry['model'];
        }
        return $models;
    }

    public function normalize(array $ref): array
    {
        $data = Validator::make($ref, [
            'type' => ['required', Rule::in([...array_keys($this->models()), 'indexes'])],
            'id' => ['required'], 'locale' => ['required', Rule::in(Locale::installedCodes())],
            'group' => ['sometimes', 'boolean'],
        ])->validate();
        if ($data['type'] === 'indexes') {
            abort_unless(is_string($data['id']) && isset(config('cms.custom_code.indexes', [])[$data['id']]), 422, 'Unregistered public index.');
            abort_if($data['group'] ?? false, 422, 'Indexes have exact locale identities.');
        } else {
            abort_unless(is_numeric($data['id']) && (int) $data['id'] > 0, 422, 'A stable positive entity ID is required.');
            $data['id'] = (int) $data['id'];
        }
        return $data + ['group' => false];
    }

    public function record(array $ref): ?Model
    {
        return isset($this->models()[$ref['type']]) ? $this->models()[$ref['type']]::whereKey($ref['id'])->where('locale', $ref['locale'])->first() : null;
    }

    public function exists(array $ref): bool
    {
        return $ref['type'] === 'indexes' ? isset(config('cms.custom_code.indexes', [])[$ref['id']]) : $this->record($ref) !== null;
    }

    public function matches(array $ref, array $target): bool
    {
        if (! $this->exists($ref) || $ref['type'] !== $target['type']) return false;
        if (! ($ref['group'] ?? false)) return (string) $ref['id'] === (string) $target['id'] && $ref['locale'] === $target['locale'];
        $origin = $this->record($ref); $destination = $this->record($target);
        return $origin && $destination && $origin->translation_group_id && $origin->translation_group_id === $destination->translation_group_id;
    }

    public function forModel(Model $model): ?array
    {
        foreach ($this->models() as $type => $class) if ($model instanceof $class) return ['type' => $type, 'id' => $model->getKey(), 'locale' => $model->locale, 'group' => false];
        return null;
    }

    public function list(array $input = []): array
    {
        $data = Validator::make($input, ['type' => ['nullable', Rule::in([...array_keys($this->models()), 'indexes'])], 'page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']])->validate();
        $page = (int) ($data['page'] ?? 1); $size = (int) ($data['per_page'] ?? 20);
        $offset = ($page - 1) * $size; $rows = []; $total = 0;
        foreach ($this->models() as $type => $class) {
            if (isset($data['type']) && $data['type'] !== $type) continue;
            $count = $class::count(); $total += $count;
            if ($offset >= $count) { $offset -= $count; continue; }
            if (count($rows) < $size) foreach ($class::orderBy('id')->skip($offset)->take($size - count($rows))->get() as $model) $rows[] = $this->forModel($model) + ['title' => mb_substr($model->title, 0, 255), 'url' => $model->url()];
            $offset = 0;
        }
        if (! isset($data['type']) || $data['type'] === 'indexes') {
            $indexes = [];
            foreach (config('cms.custom_code.indexes', []) as $id => $entry) foreach (Locale::installedCodes() as $locale) $indexes[] = ['type' => 'indexes', 'id' => $id, 'locale' => $locale, 'group' => false, 'title' => $entry['label'], 'url' => null];
            $total += count($indexes);
            array_push($rows, ...array_slice($indexes, $offset, $size - count($rows)));
        }
        return ['destinations' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $size, 'last_page' => max(1, (int) ceil($total / $size))];
    }
}
