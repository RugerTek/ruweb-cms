<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Ordered, typed composition data shared by every editorial entry point. */
final class CompositionBlocks
{
    public const MAX_BLOCKS = 60;

    public function normalize(array $blocks): array
    {
        if (! array_is_list($blocks) || count($blocks) > self::MAX_BLOCKS) {
            $this->fail('Blocks must be an ordered list of at most '.self::MAX_BLOCKS.' entries.');
        }
        $result = [];
        $ids = [];
        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                $this->fail('Each block must be an object.');
            }
            $type = $block['type'] ?? null;
            $fields = $type === 'html' ? ['id', 'type', 'version', 'is_visible', 'html'] : ['id', 'type', 'version', 'is_visible', 'component', 'params'];
            if (array_diff(array_keys($block), $fields)) {
                $this->fail('Block contains unsupported fields.');
            }
            $validated = Validator::make($block, [
                'id' => ['required', 'string', 'max:80', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D'],
                'type' => ['required', 'in:html,structured,interactive'],
                'version' => ['sometimes', 'integer', 'in:1'],
                'is_visible' => ['sometimes', 'boolean'],
                'html' => $type === 'html' ? ['required', 'string'] : ['prohibited'],
                'component' => $type === 'html' ? ['prohibited'] : ['required', 'string', 'max:80'],
                'params' => $type === 'html' ? ['prohibited'] : ['required', 'array'],
            ])->validate();
            if (isset($ids[$validated['id']])) {
                $this->fail('Block identities must be unique.');
            }
            $ids[$validated['id']] = true;
            $normalized = [
                'id' => $validated['id'],
                'type' => $type,
                'version' => 1,
                'is_visible' => (bool) ($validated['is_visible'] ?? true),
            ];
            if ($type === 'html') {
                $normalized['html'] = app(CompositionHtmlSanitizer::class)->sanitize($validated['html']);
            } else {
                $component = $validated['component'];
                $definition = config('cms.composition.components.'.$component);
                if (! is_array($definition) || ($definition['type'] ?? null) !== $type || ! is_array($definition['rules'] ?? null)) {
                    $this->fail('Component is not registered for this block type.');
                }
                $rules = $definition['rules'];
                $this->checkParamKeys($validated['params'], '', array_keys($rules));
                $params = Validator::make($validated['params'], $rules)->validate();
                $normalized['component'] = $component;
                $normalized['params'] = $params;
            }
            $result[] = $normalized;
        }

        return $result;
    }

    /** Unknown parameter keys are rejected even inside arrays of registered items. */
    private function checkParamKeys(array $params, string $prefix, array $paths): void
    {
        foreach ($params as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $schemaPath = implode('.', array_map(fn ($part) => ctype_digit($part) ? '*' : $part, explode('.', $path)));
            $known = false;
            foreach ($paths as $allowed) {
                if ($schemaPath === $allowed || str_starts_with($allowed, $schemaPath.'.')) {
                    $known = true;
                    break;
                }
            }
            if (! $known) {
                $this->fail('Unsupported component parameter: '.$path.'.');
            }
            if (is_array($value)) {
                $this->checkParamKeys($value, $path, $paths);
            }
        }
    }

    public static function mediaIds(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'html') {
                $ids = array_merge($ids, CompositionHtmlSanitizer::mediaIds($block['html'] ?? null));
            } elseif (isset($block['params']['image_id']) && filter_var($block['params']['image_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
                $ids[] = (int) $block['params']['image_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['content_json' => $message]);
    }

    /** Layout/configuration changes do not invalidate translated copy. Block order is independent. */
    public static function translatableCopy(array $blocks): array
    {
        $copy = [];
        foreach ($blocks as $block) {
            $id = $block['id'];
            if ($block['type'] === 'html') {
                $copy[$id] = ['type' => 'html', 'html' => $block['html'] ?? ''];

                continue;
            }
            $component = $block['component'];
            $params = [];
            foreach (config('cms.composition.components.'.$component.'.translatable_paths', []) as $path) {
                $params[$path] = data_get($block['params'] ?? [], $path);
            }
            $copy[$id] = ['type' => $block['type'], 'component' => $component, 'copy' => $params];
        }
        ksort($copy);

        return $copy;
    }
}
