<?php

namespace App\ValueObjects;

use InvalidArgumentException;
use NumberFormatter;

final class Money
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency = 'PYG',
    ) {}

    public static function fromInt(int $amount, ?string $currency = null): self
    {
        return new self($amount, $currency ?? config('cms.shop.currency', 'PYG'));
    }

    public static function zero(?string $currency = null): self
    {
        return new self(0, $currency ?? config('cms.shop.currency', 'PYG'));
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    /**
     * The currency decides the format, not the interface language: Guaraníes are always "Gs. 150.000" (dot thousands,
     * no decimals) in every locale. Other currencies use the locale's currency format.
     */
    public function format(?string $locale = null): string
    {
        if ($this->currency === 'PYG') {
            return 'Gs. '.number_format($this->amount, 0, ',', '.');
        }

        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($this->amount, $this->currency);
    }

    public function __toString(): string
    {
        return $this->format();
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot operate on Money objects with different currencies: {$this->currency} vs {$other->currency}",
            );
        }
    }
}
