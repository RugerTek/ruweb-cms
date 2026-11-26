<?php

namespace App\Connectors\Meta\Graph;

use DateTimeImmutable;

/** Small readers shared by the Graph value objects (Meta sends ids as strings and dates as `2026-11-24T22:09:12+0000`). */
final class MetaGraphValues
{
    public static function string(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    public static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sO', $value);

        return $date === false ? null : $date;
    }

    /**
     * The `data` list of a Graph edge (paging is ignored: the edges Ruweb reads are small).
     *
     * @param  array<string, mixed>  $json
     * @return list<array<string, mixed>>
     */
    public static function data(array $json): array
    {
        $data = $json['data'] ?? [];

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }
}
