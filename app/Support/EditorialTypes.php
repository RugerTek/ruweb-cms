<?php

namespace App\Support;

use App\Models\Page;
use App\Models\Post;
use Illuminate\Database\Eloquent\Model;

/**
 * Single read path for the content types that own editorial history, revisions, previews and translation
 * tools (`/admin/editorial/{type}`, `content_*` MCP tools, `cms_schema`, `cms:expire-content`…).
 *
 * Pages and posts are always editorial. A registered collection joins when its `cms.collections` entry declares
 * `'editorial_history' => true`; the key must be the model's table, because history rows store the table as
 * `content_type`. Opt-in on purpose: the shared editorial operations must support a model before its routes
 * and tools accept it.
 */
final class EditorialTypes
{
    /** @return array<string, class-string<Model>> type key (table) => model */
    public static function models(): array
    {
        $types = ['pages' => Page::class, 'posts' => Post::class];
        foreach (config('cms.collections', []) as $key => $collection) {
            if (! isset($types[$key]) && ($collection['editorial_history'] ?? false) === true && isset($collection['model'])) {
                $types[$key] = $collection['model'];
            }
        }

        return $types;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::models());
    }

    public static function has(string $type): bool
    {
        return array_key_exists($type, self::models());
    }

    /** @return class-string<Model>|null */
    public static function model(string $type): ?string
    {
        return self::models()[$type] ?? null;
    }

    /** Regex alternation of the type keys, already quoted for `#` delimiters. */
    public static function pattern(): string
    {
        return implode('|', array_map(fn (string $type): string => preg_quote($type, '#'), self::all()));
    }
}
