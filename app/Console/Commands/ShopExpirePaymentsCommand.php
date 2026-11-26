<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Shop\OrderOperations;
use App\Services\Shop\PaymentOperations;
use App\Services\Shop\StrayChargeOperations;
use App\Shop\Payments\Bancard\BancardProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Abandoned-payment sweep (PLAN.md E3.2 step 8, manual 0.3.1 §3.1.2), scheduled every 5 minutes by
 * routes/console.php whatever the shop mode.
 *
 * 1. Bancard attempts still `pending` that reached Bancard (process_id) and have not moved for
 *    cms.shop.checkout.bancard_pending_minutes (10) are asked with get_confirmation. A verified answer is applied
 *    (paid late → confirmed; rejected → failed; another amount → failed and reversed, StrayChargeOperations).
 *    Nothing paid → rollback, and the attempt expires once Bancard
 *    accepted it; if the rollback fails the attempt stays pending for the next run. Bancard unreachable or an
 *    answer that does not verify → left untouched for the next run.
 *    "Not moved" is measured on updated_at too, so an old attempt whose iframe was opened a minute ago is not swept
 *    while the customer is still paying.
 * 2. OrderOperations::cancelUnpaid(): orders with an automatic method still unpaid after unpaid_order_hours.
 */
class ShopExpirePaymentsCommand extends Command
{
    protected $signature = 'shop:expire-payments';

    protected $description = 'Query and roll back abandoned Bancard payments, then cancel unpaid orders past their deadline.';

    public function handle(BancardProvider $bancard, PaymentOperations $payments, OrderOperations $orders, StrayChargeOperations $charges): int
    {
        $minutes = max(1, (int) config('cms.shop.checkout.bancard_pending_minutes', 10));
        $before = now()->subMinutes($minutes);
        $counts = ['applied' => 0, 'expired' => 0, 'kept' => 0];

        Payment::query()
            ->pendingSince(BancardProvider::KEY, $before)
            ->where('updated_at', '<=', $before)
            ->whereNotNull('process_id')
            ->lazyById(100)
            ->each(function (Payment $payment) use ($bancard, $payments, $charges, &$counts) {
                $counts[$this->sweep($payment, $bancard, $payments, $charges)]++;
            });

        $cancelled = $orders->cancelUnpaid();

        $this->line(sprintf(
            'Bancard attempts: %d applied, %d expired, %d kept for the next run. Unpaid orders cancelled: %d.',
            $counts['applied'], $counts['expired'], $counts['kept'], $cancelled,
        ));

        return self::SUCCESS;
    }

    /** @return 'applied'|'expired'|'kept' */
    private function sweep(Payment $payment, BancardProvider $bancard, PaymentOperations $payments, StrayChargeOperations $charges): string
    {
        $context = ['payment' => $payment->id];

        try {
            $notification = $bancard->query($payment);
        } catch (RuntimeException $exception) {
            Log::warning('Bancard sweep could not query an attempt: '.$exception->getMessage(), $context);

            return 'kept';
        }

        if ($notification !== null) {
            if (! $bancard->verify($notification, $payment)) {
                Log::warning('Bancard sweep got a confirmation that does not verify.', $context);

                return 'kept';
            }
            $charges->apply($notification, $payment);

            return 'applied';
        }

        $result = $bancard->cancel($payment);
        if (! $result->ok) {
            Log::warning('Bancard sweep could not roll back an abandoned attempt.', $context + ['code' => $result->code]);

            return 'kept';
        }
        $payments->expire($payment);

        return 'expired';
    }
}
