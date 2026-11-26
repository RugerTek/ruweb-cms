<?php

namespace App\Shop\Payments;

/**
 * Result of PaymentProvider::cancel(). `ok`: the service reversed it (or there was nothing to reverse).
 * `manualRequired`: it could not, and the merchant must return the money by hand (Bancard
 * TransactionAlreadyConfirmed, or any manual method that was already paid). `code` is the provider's answer
 * (Bancard message key such as `RollbackSuccessful`) or a provider-defined identifier; never a secret.
 */
final class CancelResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly bool $manualRequired = false,
        public readonly ?string $code = null,
    ) {}

    public static function success(?string $code = null): self
    {
        return new self(true, false, $code);
    }

    public static function manual(?string $code = null): self
    {
        return new self(false, true, $code);
    }

    public static function failed(?string $code = null): self
    {
        return new self(false, false, $code);
    }
}
