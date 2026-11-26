<?php

namespace App\Shop\Payments\Bancard;

use App\Models\PaymentMethod;
use InvalidArgumentException;
use LogicException;
use SensitiveParameter;

/**
 * One environment's key pair of a site's Bancard commerce (PLAN.md E3.4), read from the encrypted secrets of the
 * `bancard` payment method. The private key is only reachable through privateKey(), for BancardToken: it is hidden
 * from var_dump/print_r/stack traces and the object refuses to be serialized (queues, cache, sessions), so it cannot
 * end up in a log, a job payload or a snapshot by accident.
 */
final class BancardCredentials
{
    private readonly string $privateKey;

    /**
     * @throws InvalidArgumentException for an unknown environment or an empty key
     */
    public function __construct(
        public readonly string $environment,
        public readonly string $publicKey,
        #[SensitiveParameter] string $privateKey,
    ) {
        if (! in_array($environment, PaymentMethod::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('Unknown Bancard environment: '.$environment);
        }
        if ($publicKey === '' || $privateKey === '') {
            throw new InvalidArgumentException('Bancard credentials need both keys.');
        }
        $this->privateKey = $privateKey;
    }

    /**
     * The pair of $environment (default: the method's active one), or null when it is missing, incomplete or cannot be
     * decrypted on this site. Whether the keys belong to this site (bound_origin) is the provider's call, not this one.
     */
    public static function fromMethod(PaymentMethod $method, ?string $environment = null): ?self
    {
        $environment ??= $method->environment;
        if (! is_string($environment) || ! in_array($environment, PaymentMethod::ENVIRONMENTS, true)) {
            return null;
        }
        $pair = $method->credentials($environment);

        return $pair === null ? null : new self($environment, $pair['public_key'], $pair['private_key']);
    }

    /** For BancardToken only: never log it, show it or put it in an exception. */
    public function privateKey(): string
    {
        return $this->privateKey;
    }

    /** Last 4 characters of the public key, the most the panel shows ("termina en …"). */
    public function publicKeySuffix(): string
    {
        return substr($this->publicKey, -4);
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['environment' => $this->environment, 'publicKey' => '…'.$this->publicKeySuffix(), 'privateKey' => '[hidden]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Bancard credentials cannot be serialized: read them from the payment method when needed.');
    }

    /** @param  array<string, mixed>  $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Bancard credentials cannot be unserialized.');
    }
}
