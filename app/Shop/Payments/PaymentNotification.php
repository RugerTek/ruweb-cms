<?php

namespace App\Shop\Payments;

use App\ValueObjects\Money;

/**
 * The outcome of an attempt as a provider reported it, from a notification (webhook) or a query. Unverified until
 * PaymentProvider::verify() says otherwise. `reference` is the attempt identifier the provider echoes back (Bancard:
 * shop_process_id as a string). `payload` is what the provider sent, for payment_events and verify(); it is never
 * shown to the customer (Bancard forbids showing response_code, extended_response_description and
 * security_information).
 */
final class PaymentNotification
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $reference,
        public readonly bool $approved,
        public readonly ?Money $amount = null,
        public readonly ?string $responseCode = null,
        public readonly ?string $responseDescription = null,
        public readonly ?string $authorizationNumber = null,
        public readonly ?string $ticketNumber = null,
        public readonly array $payload = [],
    ) {}

    /** Whether the reported amount and currency equal the attempt's (false when the provider sent none). */
    public function matchesAmount(Money $expected): bool
    {
        return $this->amount !== null && $this->amount->equals($expected);
    }
}
