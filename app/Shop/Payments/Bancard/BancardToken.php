<?php

namespace App\Shop\Payments\Bancard;

use App\ValueObjects\Money;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * The md5 tokens of Bancard vPOS 2.0 (PLAN.md E3.1; manual 0.3.1 §2 and the official SDK `api.py`). The private
 * key never travels on its own: only inside these hashes. Every function marks it #[SensitiveParameter] so it does
 * not show up in stack traces.
 *
 *   single_buy:        md5(private_key + shop_process_id + amount + currency)
 *   confirmation:      md5(private_key + shop_process_id + "confirm" + amount + currency)   (webhook and confirmations answer)
 *   get_confirmation:  md5(private_key + shop_process_id + "get_confirmation")
 *   rollback:          md5(private_key + shop_process_id + "rollback" + "0.00")
 *
 * `amount` is always the Bancard string with two decimals and a dot ("150000.00", see amount()).
 */
final class BancardToken
{
    /** The only currency Bancard vPOS takes (constants.py of the official SDK: BANCARD_ALLOWED_CURRENCIES). */
    public const CURRENCY = 'PYG';

    public static function singleBuy(#[SensitiveParameter] string $privateKey, int|string $shopProcessId, string $amount, string $currency): string
    {
        return md5($privateKey.$shopProcessId.$amount.$currency);
    }

    public static function confirm(#[SensitiveParameter] string $privateKey, int|string $shopProcessId, string $amount, string $currency): string
    {
        return md5($privateKey.$shopProcessId.'confirm'.$amount.$currency);
    }

    public static function getConfirmation(#[SensitiveParameter] string $privateKey, int|string $shopProcessId): string
    {
        return md5($privateKey.$shopProcessId.'get_confirmation');
    }

    public static function rollback(#[SensitiveParameter] string $privateKey, int|string $shopProcessId): string
    {
        return md5($privateKey.$shopProcessId.'rollback'.'0.00');
    }

    /** Constant-time comparison of an expected token with whatever arrived (non-strings never match). */
    public static function matches(string $expected, mixed $given): bool
    {
        return is_string($given) && hash_equals($expected, strtolower($given));
    }

    /**
     * Bancard amount: integer guaraníes with two decimals and a dot, no thousands separator (150000 → "150000.00").
     *
     * @throws InvalidArgumentException for a currency other than PYG or a non-positive amount
     */
    public static function amount(Money $money): string
    {
        if ($money->currency !== self::CURRENCY) {
            throw new InvalidArgumentException('Bancard only charges in '.self::CURRENCY.', not '.$money->currency.'.');
        }
        if ($money->amount <= 0) {
            throw new InvalidArgumentException('Bancard amounts must be greater than zero.');
        }

        return number_format($money->amount, 2, '.', '');
    }
}
