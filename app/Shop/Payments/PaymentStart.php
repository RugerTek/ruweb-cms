<?php

namespace App\Shop\Payments;

use InvalidArgumentException;

/**
 * What the order page shows to collect a payment (PaymentProvider::start()): `instructions` for manual methods
 * (the public settings of the method, e.g. bank details), or an `iframe` the page mounts with the provider script
 * (Bancard: `Bancard.Checkout.createForm(container, processId)` from scriptUrl).
 */
final class PaymentStart
{
    public const KIND_INSTRUCTIONS = 'instructions';

    public const KIND_IFRAME = 'iframe';

    /**
     * @param  array<string, string>  $instructions  public, non-empty values keyed by field (`bank_name`, `instructions`…)
     *
     * @throws InvalidArgumentException when the kind is unknown or an iframe lacks its process id or script URL
     */
    public function __construct(
        public readonly string $kind,
        public readonly ?string $processId = null,
        public readonly ?string $scriptUrl = null,
        public readonly array $instructions = [],
    ) {
        if (! in_array($kind, [self::KIND_INSTRUCTIONS, self::KIND_IFRAME], true)) {
            throw new InvalidArgumentException('Unknown payment start kind: '.$kind);
        }
        if ($kind === self::KIND_IFRAME && (($processId ?? '') === '' || ($scriptUrl ?? '') === '')) {
            throw new InvalidArgumentException('An iframe payment start needs a process id and a script URL.');
        }
    }

    /** @param  array<string, string>  $instructions */
    public static function instructions(array $instructions): self
    {
        return new self(self::KIND_INSTRUCTIONS, instructions: $instructions);
    }

    public static function iframe(string $processId, string $scriptUrl): self
    {
        return new self(self::KIND_IFRAME, $processId, $scriptUrl);
    }

    public function isIframe(): bool
    {
        return $this->kind === self::KIND_IFRAME;
    }
}
