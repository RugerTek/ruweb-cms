<?php

namespace App\Events\Shop;

use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payment attempt became `failed` (App\Services\Shop\PaymentOperations::fail(), once per attempt): rejected by
 * the provider, failed by the administrator, or reported with a different amount ($reason REASON_AMOUNT_MISMATCH,
 * which also warns the administrator). The order stays `pending` and the customer can try again.
 */
final class PaymentFailed
{
    use Dispatchable, SerializesModels;

    /** The provider rejected the payment (Bancard response_code other than 00). */
    public const REASON_REJECTED = 'rejected';

    /** Failed by the administrator from the panel or MCP. */
    public const REASON_ADMIN = 'admin';

    /** A verified notification reported another amount or currency. */
    public const REASON_AMOUNT_MISMATCH = 'amount_mismatch';

    public function __construct(
        public readonly Payment $payment,
        public readonly string $reason = self::REASON_REJECTED,
    ) {}
}
