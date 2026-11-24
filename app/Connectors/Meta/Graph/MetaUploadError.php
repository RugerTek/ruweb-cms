<?php

namespace App\Connectors\Meta\Graph;

/**
 * One entry of `GET /{upload}/errors?fields=id,summary,description,severity,error_type,total_count`, already in the
 * language of the token's user (`feed_file_not_found`, `wrong_format`, severity `fatal`/`warning`).
 */
final class MetaUploadError
{
    public const FIELDS = 'id,summary,description,severity,error_type,total_count';

    public function __construct(
        public readonly string $id,
        public readonly string $summary,
        public readonly ?string $description,
        public readonly ?string $errorType,
        public readonly ?string $severity,
        public readonly int $totalCount = 0,
    ) {}

    /** @param  array<string, mixed>  $json */
    public static function fromGraph(array $json): self
    {
        return new self(
            (string) ($json['id'] ?? ''),
            (string) ($json['summary'] ?? ''),
            MetaGraphValues::string($json['description'] ?? null),
            MetaGraphValues::string($json['error_type'] ?? null),
            MetaGraphValues::string($json['severity'] ?? null),
            (int) ($json['total_count'] ?? 0),
        );
    }

    public function isFatal(): bool
    {
        return $this->severity === 'fatal';
    }
}
