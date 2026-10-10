<?php

namespace App\Mcp;

/** Bound editorial payloads with explicit truncation; never silently present clipped copy as complete. */
final class ContentResponse
{
    public static function bounded(array $data, int $budget = 65536): array
    {
        $protected = array_intersect_key($data, array_flip(['id', 'revision', 'source_fingerprint', 'source_id']));
        $data = array_diff_key($data, $protected);
        $truncated = [];
        $walk = function (mixed $value, string $path = '', int $depth = 0) use (&$walk, &$budget, &$truncated): mixed {
            if (is_string($value)) {
                $limit = max(0, min(8192, $budget));
                $budget -= min(strlen($value), $limit);
                if (strlen($value) > $limit) {
                    $truncated[] = $path;

                    return mb_strcut($value, 0, $limit, 'UTF-8');
                }
            } elseif (is_array($value)) {
                if ($depth > 12 || $budget <= 0) {
                    $truncated[] = $path;

                    return [];
                }
                $result = [];
                foreach (array_slice($value, 0, 100, true) as $key => $item) {
                    if (strlen((string) $key) > 255 || strlen((string) $key) + 16 > $budget) {
                        if (count($truncated) < 100) {
                            $truncated[] = 'omitted_key_sha256:'.hash('sha256', (string) $key);
                        }

                        continue;
                    }
                    $budget -= strlen((string) $key) + 16;
                    $result[$key] = $walk($item, $path === '' ? (string) $key : $path.'.'.$key, $depth + 1);
                }
                if (count($value) > 100) {
                    $truncated[] = $path;
                }

                return $result;
            }

            return $value;
        };
        $result = $protected + $walk($data);
        if ($truncated !== []) {
            $result['truncated_fields'] = array_map(fn ($path) => strlen($path) > 500 ? substr($path, 0, 400).':sha256:'.hash('sha256', $path) : $path, array_slice(array_values(array_unique($truncated)), 0, 100));
            $result['recovery'] = 'Use content_read_field for paginated string or array values. Do not submit this clipped response as a replacement document.';
        }

        return $result;
    }
}
