<?php

namespace App\Connectors\Meta\Connect;

use RuntimeException;

/**
 * A RugerTek Connect call that failed: the broker's `{"error": "<code>", "message": "…"}` with its HTTP status, a
 * connection failure (status 0, `connection`), a misconfiguration (`not_configured`, `insecure_url`) or an answer
 * that is not the documented JSON (`unexpected`). Never carries a secret or a token.
 */
final class RuwebConnectException extends RuntimeException
{
    public const CONNECTION = 'connection';

    public const NOT_CONFIGURED = 'not_configured';

    public const INSECURE_URL = 'insecure_url';

    public const UNEXPECTED = 'unexpected';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
