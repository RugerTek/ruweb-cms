<?php

namespace App\Shop\Payments\Bancard;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\ValueObjects\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * HTTP client of the Bancard vPOS 2.0 API, "Compra Simple" (PLAN.md E3.1, manual 0.3.1 §3): single_buy,
 * get_confirmation and rollback. It only talks and translates: it never touches orders or payments
 * (BancardProvider and App\Services\Shop\PaymentOperations do).
 *
 * - URL per environment from cms.shop.bancard.urls; cms.shop.bancard.staging_url_override (BANCARD_STAGING_URL, the
 *   fake server of tests/Support/FakeBancardServer) replaces staging ONLY when app()->environment('local', 'testing'):
 *   a real site can never be pointed elsewhere by an environment variable.
 * - Bodies are JSON with PHP's default escaping (non-ASCII as \uXXXX, as manual 0.3.1 §6 asks). No retries:
 *   single_buy is not idempotent, and the callers decide (a new attempt gets a new shop_process_id).
 * - Bancard error answers come back as BancardResponse values; timeouts, connection failures and answers that are not
 *   the documented JSON throw BancardException.
 * - Every call is logged (operation, environment, shop_process_id, status, message key, ms): never a key, a token or
 *   a body. Credentials are #[SensitiveParameter] everywhere so traces do not carry them either.
 */
final class BancardClient
{
    public const OPERATION_SINGLE_BUY = 'single_buy';

    public const OPERATION_GET_CONFIRMATION = 'get_confirmation';

    public const OPERATION_ROLLBACK = 'rollback';

    public const PATH_SINGLE_BUY = '/vpos/api/0.3/single_buy';

    public const PATH_CONFIRMATIONS = '/vpos/api/0.3/single_buy/confirmations';

    public const PATH_ROLLBACK = '/vpos/api/0.3/single_buy/rollback';

    /** Field limits of manual 0.3.1 §2. */
    public const DESCRIPTION_MAX = 100;

    public const URL_MAX = 255;

    public const DEFAULT_TIMEOUT_SECONDS = 20;

    public const DEFAULT_CONNECT_TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        private readonly int $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
    ) {}

    /**
     * Start a payment: Bancard answers `{"status": "success", "process_id": "…"}` for the iframe. $description is
     * trimmed to 100 characters; cancel_url is sent only when given (Bancard falls back to return_url).
     *
     * @throws InvalidArgumentException for an invalid shop_process_id, a non-PYG or non-positive amount, an empty
     *                                  description or a URL longer than 255 characters
     * @throws BancardException on timeout, connection failure or an undocumented answer
     */
    public function singleBuy(
        #[SensitiveParameter] BancardCredentials $credentials,
        int $shopProcessId,
        Money $amount,
        string $description,
        string $returnUrl,
        ?string $cancelUrl = null,
    ): BancardResponse {
        self::assertShopProcessId($shopProcessId);
        $value = BancardToken::amount($amount);
        $description = mb_substr(trim($description), 0, self::DESCRIPTION_MAX);
        if ($description === '') {
            throw new InvalidArgumentException('Bancard single_buy needs a description.');
        }
        foreach (['return_url' => $returnUrl, 'cancel_url' => $cancelUrl] as $field => $url) {
            if ($url !== null && ($url === '' || strlen($url) > self::URL_MAX)) {
                throw new InvalidArgumentException('Bancard '.$field.' must have 1 to '.self::URL_MAX.' characters.');
            }
        }

        $operation = [
            'token' => BancardToken::singleBuy($credentials->privateKey(), $shopProcessId, $value, $amount->currency),
            'shop_process_id' => $shopProcessId,
            'amount' => $value,
            'currency' => $amount->currency,
            'description' => $description,
            'return_url' => $returnUrl,
        ];
        if ($cancelUrl !== null) {
            $operation['cancel_url'] = $cancelUrl;
        }

        $response = $this->post($credentials, self::OPERATION_SINGLE_BUY, self::PATH_SINGLE_BUY, $shopProcessId, $operation);
        if ($response->successful() && $response->processId() === null) {
            throw new BancardException(BancardException::UNEXPECTED, self::OPERATION_SINGLE_BUY, 'Bancard single_buy succeeded without a process_id.', $response);
        }

        return $response;
    }

    /**
     * Ask for the outcome of a buy. Success carries `confirmation` (check its token with BancardToken::confirm());
     * PaymentNotFoundError = not paid (yet); BuyNotFoundError = unknown shop_process_id.
     *
     * @throws BancardException on timeout, connection failure or an undocumented answer
     */
    public function getConfirmation(#[SensitiveParameter] BancardCredentials $credentials, int $shopProcessId): BancardResponse
    {
        self::assertShopProcessId($shopProcessId);

        return $this->post($credentials, self::OPERATION_GET_CONFIRMATION, self::PATH_CONFIRMATIONS, $shopProcessId, [
            'token' => BancardToken::getConfirmation($credentials->privateKey(), $shopProcessId),
            'shop_process_id' => $shopProcessId,
        ]);
    }

    /**
     * Reverse a buy (refund, cancelled or abandoned). Success → RollbackSuccessful; TransactionAlreadyConfirmed →
     * already settled, the merchant reverses it by hand with Bancard.
     *
     * @throws BancardException on timeout, connection failure or an undocumented answer
     */
    public function rollback(#[SensitiveParameter] BancardCredentials $credentials, int $shopProcessId): BancardResponse
    {
        self::assertShopProcessId($shopProcessId);

        return $this->post($credentials, self::OPERATION_ROLLBACK, self::PATH_ROLLBACK, $shopProcessId, [
            'token' => BancardToken::rollback($credentials->privateKey(), $shopProcessId),
            'shop_process_id' => $shopProcessId,
        ]);
    }

    /**
     * Base URL of an environment, without trailing slash. Staging honours cms.shop.bancard.staging_url_override only
     * in the local and testing environments.
     *
     * @throws InvalidArgumentException for an unknown environment
     */
    public static function baseUrl(string $environment): string
    {
        if (! in_array($environment, PaymentMethod::ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('Unknown Bancard environment: '.$environment);
        }
        $override = config('cms.shop.bancard.staging_url_override');
        if ($environment === PaymentMethod::ENVIRONMENT_STAGING && is_string($override) && trim($override) !== ''
            && app()->environment('local', 'testing')) {
            return rtrim(trim($override), '/');
        }

        return rtrim((string) config('cms.shop.bancard.urls.'.$environment), '/');
    }

    /** Origin the iframe posts messages from (scheme://host[:port]), what the checkout library checks. */
    public static function origin(string $environment): string
    {
        $parts = parse_url(self::baseUrl($environment));

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /** {env}/checkout/javascript/dist/bancard-checkout-<cms.shop.bancard.checkout_js_version>.js */
    public static function checkoutScriptUrl(string $environment): string
    {
        return self::baseUrl($environment).'/checkout/javascript/dist/bancard-checkout-'.config('cms.shop.bancard.checkout_js_version').'.js';
    }

    /**
     * shop_process_id of an attempt: its own id (PLAN.md E3.2).
     *
     * @throws InvalidArgumentException when the attempt has none or it is out of Bancard's range
     */
    public static function shopProcessId(Payment $payment): int
    {
        $id = $payment->shop_process_id;
        if (! is_int($id)) {
            throw new InvalidArgumentException('The payment has no shop_process_id.');
        }
        self::assertShopProcessId($id);

        return $id;
    }

    private static function assertShopProcessId(int $shopProcessId): void
    {
        if ($shopProcessId < 1 || $shopProcessId > Payment::MAX_SHOP_PROCESS_ID) {
            throw new InvalidArgumentException('Bancard shop_process_id must be between 1 and '.Payment::MAX_SHOP_PROCESS_ID.'.');
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     *
     * @throws BancardException
     */
    private function post(#[SensitiveParameter] BancardCredentials $credentials, string $name, string $path, int $shopProcessId, array $operation): BancardResponse
    {
        $body = json_encode(['public_key' => $credentials->publicKey, 'operation' => $operation], JSON_THROW_ON_ERROR);
        $started = hrtime(true);
        $context = ['operation' => $name, 'environment' => $credentials->environment, 'shop_process_id' => $shopProcessId];

        try {
            $http = Http::timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->acceptJson()
                ->withBody($body, 'application/json')
                ->post(self::baseUrl($credentials->environment).$path);
        } catch (ConnectionException $exception) {
            $reason = self::isTimeout($exception) ? BancardException::TIMEOUT : BancardException::CONNECTION;
            Log::warning('Bancard call failed', $context + ['reason' => $reason, 'ms' => self::elapsed($started)]);

            throw new BancardException($reason, $name, 'Bancard '.$name.' ('.$credentials->environment.'): '.($reason === BancardException::TIMEOUT ? 'timed out' : 'could not connect').'.', null, $exception);
        }

        try {
            $response = BancardResponse::fromHttp($name, $http);
        } catch (BancardException $exception) {
            Log::warning('Bancard call failed', $context + ['reason' => BancardException::UNEXPECTED, 'http_status' => $http->status(), 'ms' => self::elapsed($started)]);

            throw $exception;
        }

        Log::log($response->successful() ? 'info' : 'warning', 'Bancard call', $context + [
            'http_status' => $http->status(),
            'status' => $response->status(),
            'key' => $response->key(),
            'ms' => self::elapsed($started),
        ]);

        return $response;
    }

    private static function isTimeout(ConnectionException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false;
    }

    private static function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
