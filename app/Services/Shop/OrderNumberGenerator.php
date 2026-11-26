<?php

namespace App\Services\Shop;

use App\Models\Order;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Order numbers `ORD-2026-000001` (prefix cms.shop.checkout.order_prefix, year of placement, six-digit sequence per
 * prefix and year). `orders.number` has a unique index: when two checkouts take the same number at once, the loser
 * retries with the next one (R9). Numbers identify orders to people; the order page is reached only by token.
 */
class OrderNumberGenerator
{
    /** Inserts tried before giving up (each one after a unique constraint violation). */
    public const MAX_ATTEMPTS = 5;

    public const SEQUENCE_DIGITS = 6;

    /** "ORD-2026-": what every number of that year starts with. */
    public function prefix(?Carbon $at = null): string
    {
        $prefix = trim((string) config('cms.shop.checkout.order_prefix', 'ORD')) ?: 'ORD';

        return $prefix.'-'.($at ?? now())->format('Y').'-';
    }

    /** The number the next order would get ($attempt > 0 skips that many numbers past the last one seen). */
    public function next(int $attempt = 0, ?Carbon $at = null): string
    {
        $prefix = $this->prefix($at);

        return $prefix.str_pad((string) ($this->lastSequence($prefix) + 1 + $attempt), self::SEQUENCE_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Run $create with a fresh number inside a savepoint, retrying with the next number when the insert hits a
     * unique constraint (another checkout took it). Re-throws the violation after MAX_ATTEMPTS.
     *
     * @template T
     *
     * @param  Closure(string): T  $create  receives the number to insert
     * @return T
     *
     * @throws UniqueConstraintViolationException
     */
    public function assign(Closure $create): mixed
    {
        for ($attempt = 0; ; $attempt++) {
            $number = $this->next($attempt);
            try {
                return DB::transaction(fn () => $create($number));
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt + 1 >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }

    /** Highest sequence already used under a prefix (0 for none). */
    protected function lastSequence(string $prefix): int
    {
        $last = Order::query()
            ->where('number', 'like', $prefix.'%')
            // Longest first: past 999999 the sequence grows a digit and plain string order would go backwards.
            ->orderByRaw('LENGTH(number) DESC')
            ->orderByDesc('number')
            ->value('number');

        return is_string($last) ? (int) substr($last, strlen($prefix)) : 0;
    }
}
