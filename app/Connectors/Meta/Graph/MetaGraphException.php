<?php

namespace App\Connectors\Meta\Graph;

use RuntimeException;

/**
 * A Graph API call that failed: Meta's `error` object (code, error_subcode, type, fbtrace_id, error_user_title/msg) or
 * a connection failure (code 0, httpStatus 0). The message never carries the token: HttpMetaGraphClient removes it.
 * Meanings verified in the spike (03-api/calls.jsonl):
 * - 190 (any subcode; 464 = the client removed the app) or 102 → revokesConnection(): drop the token, needs_reconnect.
 * - 100/33 on a catalog → objectUnavailable(): the catalog was unshared (or deleted); the token is still valid.
 * - 10/1690129 creating a catalog, 200 on portfolio edges → isPermission(): not allowed with catalog_management.
 */
final class MetaGraphException extends RuntimeException
{
    public const CODE_CONNECTION = 0;

    public const CODE_NOT_CONNECTED = -1;

    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly int $graphCode,
        public readonly ?int $subcode = null,
        public readonly ?string $type = null,
        public readonly ?string $fbtraceId = null,
        public readonly ?string $userTitle = null,
        public readonly ?string $userMessage = null,
        public readonly bool $transient = false,
    ) {
        parent::__construct($message, $graphCode);
    }

    /**
     * From Meta's error body, with every occurrence of $token removed from the texts.
     *
     * @param  array<string, mixed>  $json
     */
    public static function fromResponse(int $httpStatus, array $json, ?string $token = null, string $fallback = 'Meta Graph API error'): self
    {
        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $clean = function (mixed $text) use ($token): ?string {
            if (! is_string($text) || $text === '') {
                return null;
            }

            return mb_substr($token !== null && $token !== '' ? str_replace($token, '[token]', $text) : $text, 0, 500);
        };

        return new self(
            $clean($error['message'] ?? null) ?? $fallback,
            $httpStatus,
            (int) ($error['code'] ?? 0),
            isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            MetaGraphValues::string($error['type'] ?? null),
            MetaGraphValues::string($error['fbtrace_id'] ?? null),
            $clean($error['error_user_title'] ?? null),
            $clean($error['error_user_msg'] ?? null),
            (bool) ($error['is_transient'] ?? false) || $httpStatus >= 500,
        );
    }

    public static function connection(string $reason): self
    {
        return new self('Could not reach Meta: '.$reason, 0, self::CODE_CONNECTION, transient: true);
    }

    public static function notConnected(): self
    {
        return new self('The Meta connection has no usable access token.', 0, self::CODE_NOT_CONNECTED);
    }

    /** The token no longer works (app removed by the client, user invalidated): ask to reconnect. */
    public function revokesConnection(): bool
    {
        return $this->graphCode === 190 || $this->graphCode === 102;
    }

    /** The object (catalog, feed) does not exist or is no longer shared with the app. */
    public function objectUnavailable(): bool
    {
        return $this->graphCode === 100 && $this->subcode === 33;
    }

    public function isPermission(): bool
    {
        return $this->graphCode === 10 || ($this->graphCode >= 200 && $this->graphCode <= 299);
    }

    public function isConnectionFailure(): bool
    {
        return $this->graphCode === self::CODE_CONNECTION && $this->httpStatus === 0;
    }

    /** Worth retrying later (network, 5xx, Meta's is_transient, rate limit 4/17/32/613). Never retried blindly here. */
    public function isTransient(): bool
    {
        return $this->transient || in_array($this->graphCode, [1, 2, 4, 17, 32, 613], true);
    }

    /** Text for the panel and logs: Meta's user message when it sent one (localized), else the technical one. */
    public function displayMessage(): string
    {
        return $this->userMessage ?? $this->getMessage();
    }
}
