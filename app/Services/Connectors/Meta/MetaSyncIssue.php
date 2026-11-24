<?php

namespace App\Services\Connectors\Meta;

/**
 * One problem Meta reported about the Ruweb feed, for the «Sincronización con Meta» panel (plan-meta-v2, track 2c):
 * - a feed error of the last upload (`GET /{upload}/errors`: whole-file problems such as `feed_file_not_found`, in the
 *   language of the token's user), retailerId null;
 * - an item error or warning of an items_batch (`check_batch_request_status`, track 3a), whose `id` is the retailer id
 *   (the feed's g:id: SKU, `p<id>` or `v<id>`); MetaCatalogSync resolves it to the product and its edit link.
 */
final class MetaSyncIssue
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public function __construct(
        public readonly string $severity,
        public readonly string $summary,
        public readonly ?string $description = null,
        public readonly ?string $type = null,
        public readonly int $count = 0,
        public readonly ?string $retailerId = null,
        public readonly ?string $productTitle = null,
        public readonly ?string $editUrl = null,
    ) {}

    public function isError(): bool
    {
        return $this->severity === self::SEVERITY_ERROR;
    }

    public function isItem(): bool
    {
        return $this->retailerId !== null;
    }

    /** @return array{severity: string, summary: string, description: string|null, type: string|null, count: int, retailer_id: string|null, product_title: string|null, edit_url: string|null} */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'summary' => $this->summary,
            'description' => $this->description,
            'type' => $this->type,
            'count' => $this->count,
            'retailer_id' => $this->retailerId,
            'product_title' => $this->productTitle,
            'edit_url' => $this->editUrl,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key): ?string => is_scalar($data[$key] ?? null) && (string) $data[$key] !== '' ? (string) $data[$key] : null;

        return new self(
            $string('severity') === self::SEVERITY_WARNING ? self::SEVERITY_WARNING : self::SEVERITY_ERROR,
            $string('summary') ?? '',
            $string('description'),
            $string('type'),
            (int) ($data['count'] ?? 0),
            $string('retailer_id'),
            $string('product_title'),
            $string('edit_url'),
        );
    }
}
