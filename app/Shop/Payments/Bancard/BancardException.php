<?php

namespace App\Shop\Payments\Bancard;

use RuntimeException;
use Throwable;

/**
 * Bancard could not be used: timeout, no connection, an answer that is not the documented JSON, or an error answer
 * turned into an exception by BancardResponse::orFail(). `reason` is `timeout`, `connection`, `unexpected` or the
 * Bancard message key (`InvalidTokenError`…), and maps to lang `checkout.bancard_errors.<reason>` (userMessage()).
 * Messages name the operation and environment, never a key, a token or the request body.
 */
final class BancardException extends RuntimeException
{
    public const TIMEOUT = 'timeout';

    public const CONNECTION = 'connection';

    public const UNEXPECTED = 'unexpected';

    public function __construct(
        public readonly string $reason,
        public readonly string $operation,
        string $message,
        public readonly ?BancardResponse $response = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(BancardResponse $response): self
    {
        $key = $response->key() ?? self::UNEXPECTED;

        return new self($key, $response->operation, 'Bancard '.$response->operation.' failed: '.$key.'.', $response);
    }

    public function isTimeout(): bool
    {
        return $this->reason === self::TIMEOUT;
    }

    /** Translated message for the panel (lang checkout.bancard_errors). */
    public function userMessage(): string
    {
        return self::messageFor($this->reason, $this->response?->description());
    }

    /** checkout.bancard_errors.<reason>, else Bancard's description, else checkout.bancard_errors.unexpected. */
    public static function messageFor(string $reason, ?string $fallback = null): string
    {
        $key = 'checkout.bancard_errors.'.$reason;
        $message = __($key);
        if (is_string($message) && $message !== $key) {
            return $message;
        }
        if (is_string($fallback) && trim($fallback) !== '') {
            return $fallback;
        }

        return (string) __('checkout.bancard_errors.'.self::UNEXPECTED);
    }
}
