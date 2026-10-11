<?php

namespace App\Services\Content;

use App\Models\Page;
use App\Models\Post;
use App\Models\Taxonomy;
use App\Support\CompositionBlocks;
use App\Support\SectionRichText;
use Illuminate\Database\Eloquent\Model;

class ContentSchemaRegistry
{
    public function forEntity(Model $entity): ?array
    {
        if ($entity instanceof Page) {
            return $this->forPageTemplate((string) $entity->template_key);
        }

        if ($entity instanceof Post) {
            return config('cms.content_schemas.post');
        }

        if ($entity instanceof Taxonomy) {
            return config('cms.content_schemas.taxonomy');
        }

        return null;
    }

    public function forPageTemplate(string $templateKey): ?array
    {
        $schema = config("cms.templates.{$templateKey}.schema");

        return is_array($schema) && $schema !== [] ? $schema : null;
    }

    /**
     * Submitted page content paths the template schema does not declare. Null values are removals and always
     * allowed (so legacy keys can be deleted); composition subtrees have their own validator.
     *
     * @return array<int, string>
     */
    public function unknownPagePaths(string $templateKey, array $content): array
    {
        $paths = array_keys($this->forPageTemplate($templateKey) ?? []);
        $patterns = array_map(fn (string $path) => '/^'.str_replace('\\*', '\d+', preg_quote($path, '/')).'$/', $paths);
        $unknown = [];
        $walk = function (mixed $value, string $path) use (&$walk, &$unknown, $patterns, $paths): void {
            if ($value === null || $path === '' && $value === [] || in_array($path, ['sections.composition.blocks', 'sections.content.html'], true)) {
                return;
            }
            if (is_array($value) && $value !== []) {
                foreach ($value as $key => $child) {
                    $walk($child, $path === '' ? (string) $key : $path.'.'.$key);
                }

                return;
            }
            // An empty object or list empties a declared container, e.g. sections.features.logos: [].
            if ($value === []) {
                $container = preg_replace('/\.\d+(?=\.|$)/', '.*', $path).'.';
                foreach ($paths as $declared) {
                    if (str_starts_with($declared, $container)) {
                        return;
                    }
                }
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $path)) {
                    return;
                }
            }
            $unknown[] = $path;
        };
        $walk($content, '');

        return $unknown;
    }

    public function isTranslatable(Model $entity): bool
    {
        return $this->forEntity($entity) !== null;
    }

    public function pathsFor(Model $entity, string $flag): array
    {
        $schema = $this->forEntity($entity) ?? [];

        return array_keys(array_filter(
            $schema,
            fn (array $field): bool => (bool) ($field[$flag] ?? false)
        ));
    }

    public function extract(Model $entity, string $flag): array
    {
        $data = $this->entityData($entity);
        $values = [];

        foreach ($this->pathsFor($entity, $flag) as $path) {
            $this->extractPath($data, explode('.', $path), $values, []);
        }

        // Wrapping a legacy paragraph in <p> is initialization, not an editorial change.
        if ($flag === 'translatable') {
            $schema = $this->forEntity($entity) ?? [];
            foreach ($values as $path => $value) {
                if (($schema[$path]['type'] ?? null) === 'composition_blocks' && is_array($value)) {
                    $values[$path] = CompositionBlocks::translatableCopy($value);
                }
                $preset = $schema[$path]['rich_text'] ?? null;
                if ($preset && is_string($value)) {
                    $values[$path] = SectionRichText::comparisonValue($value, $preset);
                }
            }
        }
        ksort($values);

        return $values;
    }

    public function formNameFor(Model $entity, string $path): string
    {
        $segments = explode('.', $path);

        if ($entity instanceof Page && in_array($segments[0] ?? null, ['meta', 'sections'], true)) {
            array_unshift($segments, 'content_json');
        }

        if (count($segments) === 1) {
            return $segments[0];
        }

        $name = array_shift($segments);

        foreach ($segments as $segment) {
            $name .= '['.$segment.']';
        }

        return $name;
    }

    public function formNamesFor(Model $entity, array $paths): array
    {
        return array_values(array_map(
            fn (string $path): string => $this->formNameFor($entity, $path),
            $paths
        ));
    }

    public function copyForTranslation(Model $entity): array
    {
        return $this->entityData($entity);
    }

    private function entityData(Model $entity): array
    {
        if ($entity instanceof Page) {
            return [
                'title' => $entity->title,
                'template_key' => $entity->template_key,
                'meta' => $entity->meta(),
                'sections' => $entity->sections(),
            ];
        }

        if ($entity instanceof Post) {
            return [
                'title' => $entity->title,
                'excerpt' => $entity->excerpt,
                'content' => $entity->content,
                'meta_json' => $entity->meta_json ?? [],
            ];
        }

        if ($entity instanceof Taxonomy) {
            return [
                'name' => $entity->name,
                'description' => $entity->description,
                'type' => $entity->type,
                'order' => $entity->order,
                'parent_id' => $entity->parent_id,
            ];
        }

        return $entity->toArray();
    }

    private function extractPath(mixed $current, array $segments, array &$values, array $resolved): void
    {
        if ($segments === []) {
            $values[implode('.', $resolved)] = $current;

            return;
        }

        $segment = array_shift($segments);

        if ($segment === '*') {
            if (! is_array($current)) {
                return;
            }

            foreach ($current as $index => $item) {
                $this->extractPath($item, $segments, $values, [...$resolved, (string) $index]);
            }

            return;
        }

        if (! is_array($current) || ! array_key_exists($segment, $current)) {
            return;
        }

        $this->extractPath($current[$segment], $segments, $values, [...$resolved, $segment]);
    }
}
