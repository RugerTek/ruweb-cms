<?php

namespace App\Helpers;

use App\ValueObjects\Money;

class MoneyHelper
{
    public static function format(int|Money|null $amount, ?string $currency = null, ?string $locale = null): string
    {
        if ($amount === null) {
            return '';
        }

        $money = $amount instanceof Money
            ? $amount
            : new Money($amount, $currency ?? config('cms.shop.currency', 'PYG'));

        return $money->format($locale);
    }

    public static function parseInput(string $value): int
    {
        $stripped = preg_replace('/[^\d-]/', '', $value);

        if ($stripped === '' || $stripped === '-') {
            return 0;
        }

        return (int) $stripped;
    }
}
