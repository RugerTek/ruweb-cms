<?php

namespace App\Services\Snapshots;

use App\Support\SiteUrl;

/**
 * Rewrites one site origin into another inside stored values.
 *
 * Works on whole rows so it covers plain text, HTML and JSON columns alike.
 * JSON columns written by Laravel escape slashes (`http:\/\/host`), so both
 * spellings are replaced. Only the origin is touched; paths stay intact.
 */
final class UrlRewriter
{
    private readonly string $from;

    private readonly string $to;

    /** @var array<int, string> */
    private readonly array $needles;

    /** @var array<int, string> */
    private readonly array $replacements;

    public function __construct(string $from, string $to)
    {
        $this->from = SiteUrl::normalizeOrigin($from);
        $this->to = SiteUrl::normalizeOrigin($to);

        $this->needles = [$this->from, str_replace('/', '\/', $this->from)];
        $this->replacements = [$this->to, str_replace('/', '\/', $this->to)];
    }

    public static function between(?string $from, ?string $to): ?self
    {
        $from = SiteUrl::normalizeOrigin((string) $from);
        $to = SiteUrl::normalizeOrigin((string) $to);

        if ($from === '' || $to === '' || strcasecmp($from, $to) === 0) {
            return null;
        }

        return new self($from, $to);
    }

    public function from(): string
    {
        return $this->from;
    }

    public function to(): string
    {
        return $this->to;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function rewriteRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_string($value) && $value !== '') {
                $row[$column] = $this->rewriteString($value);
            }
        }

        return $row;
    }

    public function rewriteString(string $value): string
    {
        return str_ireplace($this->needles, $this->replacements, $value);
    }

    public function touches(string $value): bool
    {
        foreach ($this->needles as $needle) {
            if (stripos($value, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
