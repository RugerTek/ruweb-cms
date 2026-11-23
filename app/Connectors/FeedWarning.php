<?php

namespace App\Connectors;

use InvalidArgumentException;

/**
 * Why an item is left out of a feed (`skips`) or a notice about it that does not leave it out. Built with
 * FeedWarning::for(); the panel lists them with their message and a link to edit the record.
 */
final class FeedWarning
{
    public const MISSING_PRICE = 'missing_price';

    public const MISSING_IMAGE = 'missing_image';

    public const IMAGE_NOT_CONVERTIBLE = 'image_not_convertible';

    public const NO_VARIANTS = 'no_variants';

    public const DEFAULT_BRAND = 'default_brand';

    public const IMAGE_TOO_SMALL = 'image_too_small';

    public const TITLE_TRUNCATED = 'title_truncated';

    public const DESCRIPTION_FROM_TITLE = 'description_from_title';

    /** Codes that leave the item out of the feed. */
    public const SKIPPING = [self::MISSING_PRICE, self::MISSING_IMAGE, self::IMAGE_NOT_CONVERTIBLE, self::NO_VARIANTS];

    /** Codes that only warn. */
    public const NOTICES = [self::DEFAULT_BRAND, self::IMAGE_TOO_SMALL, self::TITLE_TRUNCATED, self::DESCRIPTION_FROM_TITLE];

    /**
     * @param  array<string, scalar>  $context  replacements for the message (`:limit`)
     */
    public function __construct(
        public readonly string $itemId,
        public readonly string $title,
        public readonly string $code,
        public readonly bool $skips,
        public readonly ?string $editUrl = null,
        public readonly array $context = [],
    ) {}

    /**
     * Warning about an item; `skips` follows the code.
     *
     * @param  array<string, scalar>  $context
     */
    public static function for(CatalogItem $item, string $code, array $context = []): self
    {
        if (! in_array($code, [...self::SKIPPING, ...self::NOTICES], true)) {
            throw new InvalidArgumentException('Unknown feed warning code: '.$code.'.');
        }

        return new self($item->id, $item->title, $code, in_array($code, self::SKIPPING, true), $item->editUrl, $context);
    }

    /** Translation key of the reason (`connectors.warning_missing_price`). */
    public function message(): string
    {
        return 'connectors.warning_'.$this->code;
    }
}
