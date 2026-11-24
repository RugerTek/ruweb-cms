<?php

namespace App\Connectors\Meta\Graph;

/**
 * A product catalog shared with the app (`/me/assigned_product_catalogs?fields=id,name,vertical,product_count` or
 * `GET /{catalog}`). Only `commerce` catalogs work for Ruweb: the «Crear catálogo» of Meta's dialog makes a `generic`
 * one that rejects the feed and items_batch (spike §3.2), and its vertical cannot be changed by API.
 */
final class MetaCatalog
{
    public const VERTICAL_COMMERCE = 'commerce';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $vertical = null,
        public readonly ?int $productCount = null,
    ) {}

    /** @param  array<string, mixed>  $json */
    public static function fromGraph(array $json): self
    {
        return new self((string) ($json['id'] ?? ''), (string) ($json['name'] ?? ''), MetaGraphValues::string($json['vertical'] ?? null), MetaGraphValues::int($json['product_count'] ?? null));
    }

    public function isCommerce(): bool
    {
        return $this->vertical === self::VERTICAL_COMMERCE;
    }

    /** @return array{id: string, name: string, vertical: string|null, product_count: int|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'vertical' => $this->vertical, 'product_count' => $this->productCount];
    }
}
