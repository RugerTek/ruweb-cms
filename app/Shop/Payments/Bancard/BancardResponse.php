<?php

namespace App\Shop\Payments\Bancard;

use Illuminate\Http\Client\Response;

/**
 * A parsed answer of the Bancard API (PLAN.md E3.1): `{"status": "success" | "error", "process_id"?, "confirmation"?,
 * "messages"?: [{"key", "level", "dsc"}]}`. Error answers are values, not exceptions, because several of them are
 * normal outcomes the caller decides on (get_confirmation → PaymentNotFoundError = not paid yet; rollback →
 * TransactionAlreadyConfirmed = reverse by hand). Call orFail() when any error should stop the flow.
 *
 * The HTTP status is kept but not trusted: manual 0.3.1 does not document it, so success is the JSON `status`.
 * The body never contains credentials (Bancard does not echo them), but it may hold card data such as
 * security_information: do not show it to customers (E3.1, response page).
 */
final class BancardResponse
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    // Message keys of manual 0.3.1 §3.1.2 (rollback lists every error key the API documents).
    public const KEY_INVALID_JSON = 'InvalidJsonError';

    public const KEY_UNAUTHORIZED_OPERATION = 'UnauthorizedOperationError';

    public const KEY_APPLICATION_NOT_FOUND = 'ApplicationNotFoundError';

    public const KEY_INVALID_PUBLIC_KEY = 'InvalidPublicKeyError';

    public const KEY_INVALID_TOKEN = 'InvalidTokenError';

    public const KEY_INVALID_OPERATION = 'InvalidOperationError';

    public const KEY_BUY_NOT_FOUND = 'BuyNotFoundError';

    public const KEY_PAYMENT_NOT_FOUND = 'PaymentNotFoundError';

    public const KEY_ALREADY_ROLLBACKED = 'AlreadyRollbackedError';

    public const KEY_POS_COMMUNICATION = 'PosCommunicationError';

    public const KEY_TRANSACTION_ALREADY_CONFIRMED = 'TransactionAlreadyConfirmed';

    public const KEY_ROLLBACK_SUCCESSFUL = 'RollbackSuccessful';

    /** Keys that mean the keys themselves are wrong ("Probar conexión", E3.4). */
    public const CREDENTIAL_ERROR_KEYS = [self::KEY_INVALID_PUBLIC_KEY, self::KEY_INVALID_TOKEN, self::KEY_APPLICATION_NOT_FOUND, self::KEY_UNAUTHORIZED_OPERATION];

    /**
     * @param  string  $operation  BancardClient::OPERATION_*
     * @param  array<string, mixed>  $body  decoded JSON
     */
    public function __construct(
        public readonly string $operation,
        public readonly int $httpStatus,
        public readonly array $body,
    ) {}

    /**
     * @throws BancardException (`unexpected`) when the body is not a JSON object with a `status`
     */
    public static function fromHttp(string $operation, Response $response): self
    {
        $body = json_decode($response->body(), true);
        if (! is_array($body) || (array_is_list($body) && $body !== []) || ! is_string($body['status'] ?? null)) {
            throw new BancardException(BancardException::UNEXPECTED, $operation, 'Bancard '.$operation.' answered HTTP '.$response->status().' without a JSON status.');
        }

        return new self($operation, $response->status(), $body);
    }

    public function status(): string
    {
        return (string) $this->body['status'];
    }

    public function successful(): bool
    {
        return $this->status() === self::STATUS_SUCCESS;
    }

    /**
     * Messages as Bancard sent them (malformed entries dropped).
     *
     * @return list<array{key: string, level: string|null, dsc: string|null}>
     */
    public function messages(): array
    {
        $messages = [];
        foreach (is_array($this->body['messages'] ?? null) ? $this->body['messages'] : [] as $message) {
            if (is_array($message) && is_string($message['key'] ?? null) && $message['key'] !== '') {
                $messages[] = [
                    'key' => $message['key'],
                    'level' => is_string($message['level'] ?? null) ? $message['level'] : null,
                    'dsc' => is_string($message['dsc'] ?? null) ? $message['dsc'] : null,
                ];
            }
        }

        return $messages;
    }

    /** Key of the first message (`InvalidTokenError`, `RollbackSuccessful`…), or null when there is none. */
    public function key(): ?string
    {
        return $this->messages()[0]['key'] ?? null;
    }

    /** Bancard's own description of the first message (Spanish, for the panel/logs; never contains secrets). */
    public function description(): ?string
    {
        return $this->messages()[0]['dsc'] ?? null;
    }

    /** Whether any message carries one of $keys. */
    public function hasKey(string ...$keys): bool
    {
        foreach ($this->messages() as $message) {
            if (in_array($message['key'], $keys, true)) {
                return true;
            }
        }

        return false;
    }

    /** Whether Bancard rejected the keys (wrong public key, token, commerce or permission). */
    public function rejectsCredentials(): bool
    {
        return ! $this->successful() && $this->hasKey(...self::CREDENTIAL_ERROR_KEYS);
    }

    /** single_buy: the id the iframe needs (`Bancard.Checkout.createForm(container, processId)`). */
    public function processId(): ?string
    {
        $processId = $this->body['process_id'] ?? null;

        return is_string($processId) && $processId !== '' ? $processId : null;
    }

    /**
     * get_confirmation: the `confirmation` object, same shape as the webhook `operation` (token, shop_process_id,
     * response, response_code, amount, currency, authorization_number…). Its token must still be checked with
     * BancardToken::confirm() before trusting it.
     *
     * @return array<string, mixed>|null
     */
    public function confirmation(): ?array
    {
        $confirmation = $this->body['confirmation'] ?? null;

        return is_array($confirmation) && $confirmation !== [] ? $confirmation : null;
    }

    /**
     * What to tell an admin, translated from lang/{es,en}/checkout.php `bancard_errors.<key>`; Bancard's own
     * description for keys without a translation, and `bancard_errors.unexpected` as last resort.
     */
    public function errorMessage(): string
    {
        return BancardException::messageFor($this->key() ?? BancardException::UNEXPECTED, $this->description());
    }

    /**
     * @throws BancardException with reason = the Bancard key when the answer is not a success
     */
    public function orFail(): self
    {
        if (! $this->successful()) {
            throw BancardException::fromResponse($this);
        }

        return $this;
    }
}
