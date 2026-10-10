<?php

namespace App\Mcp;

/** Map keys merge, lists replace, and null removes a key; omitted keys survive. */
final class ContentPatch
{
    public static function apply(array $current, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if ($value === null) {
                unset($current[$key]);
            } elseif (is_array($value) && ! array_is_list($value)) {
                $previous = $current[$key] ?? [];
                $current[$key] = self::apply(is_array($previous) && ! array_is_list($previous) ? $previous : [], $value);
            } else {
                $current[$key] = $value;
            }
        }

        return $current;
    }
}
