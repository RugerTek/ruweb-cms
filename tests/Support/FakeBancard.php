<?php

namespace Tests\Support;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Support\SiteUrl;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Double of the Bancard vPOS 2.0 API for tests (PLAN.md E3.1 and E3.5), installed with Http::fake on the staging and
 * production URLs of cms.shop.bancard (and the staging override when set), so no request ever reaches Bancard.
 * TestCase::useFakeBancard() installs it and stores the keys in the `bancard` payment method.
 *
 * Every request is checked like Bancard would per manual 0.3.1: public key of that environment, JSON shape, and
 * the md5 token computed with that environment's private key —
 *   single_buy:       md5(private + shop_process_id + amount + currency)
 *   confirmations:    md5(private + shop_process_id + "get_confirmation")
 *   rollback:         md5(private + shop_process_id + "rollback" + "0.00")
 *   webhook/confirm:  md5(private + shop_process_id + "confirm" + amount + currency)
 * A wrong token answers InvalidTokenError; a wrong public key InvalidPublicKeyError; a malformed operation
 * InvalidOperationError. Error bodies are `{"status": "error", "messages": [{"key", "level", "dsc"}]}` with HTTP
 * $errorStatus (the real status code is not documented in 0.3.1: clients must read the JSON `status`).
 *
 * single_buy remembers each buy (amount, currency, environment); confirmations answer per scenario for known buys
 * and BuyNotFoundError for unknown ones. Every call is recorded in calls().
 */
final class FakeBancard
{
    public const STAGING_PUBLIC_KEY = 'StagingPublicKey0123456789abcdef';

    public const STAGING_PRIVATE_KEY = 'staging-private-key-0123456789abcdefghij';

    public const PRODUCTION_PUBLIC_KEY = 'ProductionPublicKey0123456789abc';

    public const PRODUCTION_PRIVATE_KEY = 'production-private-key-0123456789abcdefg';

    /** confirmations: paid, response S / 00. rollback: RollbackSuccessful. single_buy: process_id. */
    public const APPROVED = 'approved';

    /** confirmations: answered, response N / 51 (insufficient funds). */
    public const REJECTED = 'rejected';

    /** confirmations: the buy exists but nothing was paid (PaymentNotFoundError). */
    public const NOT_PAID = 'not_paid';

    /** rollback: the transaction was already settled, reverse by hand (TransactionAlreadyConfirmed). */
    public const ALREADY_CONFIRMED = 'already_confirmed';

    /** Any operation: InvalidTokenError even with a correct token (keys changed at Bancard). */
    public const INVALID_TOKEN = 'invalid_token';

    /** Any operation: the connection times out (Illuminate\Http\Client\ConnectionException). */
    public const TIMEOUT = 'timeout';

    /**
     * single_buy: Bancard creates the buy but the answer never arrives (timeout). Afterwards a single_buy of the same
     * shop_process_id is refused (DUPLICATE_KEY; Bancard's real key for this case is not documented).
     */
    public const TIMEOUT_AFTER_CREATE = 'timeout_after_create';

    /** Error key the fake answers to a second single_buy of a shop_process_id. */
    public const DUPLICATE_KEY = 'ShopProcessIdAlreadyExistsError';

    public const OPERATION_SINGLE_BUY = 'single_buy';

    public const OPERATION_CONFIRMATIONS = 'confirmations';

    public const OPERATION_ROLLBACK = 'rollback';

    public const REJECTED_RESPONSE_CODE = '51';

    /** HTTP status of error answers. */
    public int $errorStatus = 400;

    /** @var array<string, string> operation => scenario */
    private array $scenarios = [
        self::OPERATION_SINGLE_BUY => self::APPROVED,
        self::OPERATION_CONFIRMATIONS => self::APPROVED,
        self::OPERATION_ROLLBACK => self::APPROVED,
    ];

    /** @var array<string, array{public_key: string, private_key: string}> */
    private array $keys;

    /** @var array<string, array{amount: string, currency: string, environment: string, process_id: string}> shop_process_id => buy */
    private array $buys = [];

    /** @var list<array{environment: string, operation: string, url: string, body: array<string, mixed>, response: array<string, mixed>|null}> */
    private array $calls = [];

    /**
     * @param  array<string, array{public_key: string, private_key: string}>|null  $keys  per environment; defaults to the constants
     */
    public function __construct(?array $keys = null)
    {
        $this->keys = $keys ?? [
            PaymentMethod::ENVIRONMENT_STAGING => ['public_key' => self::STAGING_PUBLIC_KEY, 'private_key' => self::STAGING_PRIVATE_KEY],
            PaymentMethod::ENVIRONMENT_PRODUCTION => ['public_key' => self::PRODUCTION_PUBLIC_KEY, 'private_key' => self::PRODUCTION_PRIVATE_KEY],
        ];
    }

    /** Fake the Bancard URLs of the current config (call again after changing them). */
    public function install(): self
    {
        $stubs = [];
        foreach ($this->baseUrls() as $environment => $bases) {
            foreach ($bases as $base) {
                $stubs[$base.'/vpos/api/0.3/*'] = fn (Request $request) => $this->handle($environment, $request);
            }
        }
        // Anything else on a Bancard host (checkout page, scripts, unknown paths) never leaves the test either.
        foreach ($this->baseUrls() as $bases) {
            foreach ($bases as $base) {
                $stubs[$base.'/*'] = Http::response('', 404);
            }
        }
        $stubs['vpos.infonet.com.py*'] = Http::response('', 404);

        Http::fake($stubs);

        return $this;
    }

    /** The key pair of an environment. @return array{public_key: string, private_key: string} */
    public function keys(string $environment = PaymentMethod::ENVIRONMENT_STAGING): array
    {
        return $this->keys[$environment];
    }

    /** Store the keys in a method as the panel would: encrypted, bound to this origin, enabled in $environment. */
    public function configure(PaymentMethod $method, string $environment = PaymentMethod::ENVIRONMENT_STAGING): PaymentMethod
    {
        $method->forceFill([
            'secrets' => $this->keys,
            'environment' => $environment,
            'bound_origin' => SiteUrl::origin(),
            'enabled' => true,
        ])->save();

        return $method;
    }

    /** Answer the next calls of $operation (OPERATION_*) with $scenario (APPROVED, REJECTED, NOT_PAID, …). */
    public function scenario(string $operation, string $scenario): self
    {
        $this->scenarios[$operation] = $scenario;

        return $this;
    }

    /** Make an attempt known to confirmations/rollback without a single_buy (e.g. created by a fixture). */
    public function registerBuy(Payment $payment, ?string $processId = null): self
    {
        $this->buys[(string) $payment->shop_process_id] = [
            'amount' => self::amount($payment),
            'currency' => $payment->currency,
            'environment' => $payment->environment ?? PaymentMethod::ENVIRONMENT_STAGING,
            'process_id' => $processId ?? $payment->process_id ?? Str::random(20),
        ];

        return $this;
    }

    /**
     * Body Bancard would POST to the confirmation URL for an attempt, with the correct token for the attempt's
     * environment. Change a field afterwards to build a forged or mismatched notification.
     *
     * @return array{operation: array<string, mixed>}
     */
    public function webhookPayload(Payment $payment, bool $approved = true, ?string $responseCode = null): array
    {
        return ['operation' => $this->confirmation(
            $payment->environment ?? PaymentMethod::ENVIRONMENT_STAGING,
            (string) $payment->shop_process_id,
            self::amount($payment),
            $payment->currency,
            $approved,
            $responseCode,
        )];
    }

    /** The monitoring ping Bancard posts every 5 minutes. @return array<never> */
    public function monitoringPayload(): array
    {
        return [];
    }

    /** md5 token of a notification (webhook or confirmations answer). */
    public function confirmToken(string $environment, string $shopProcessId, string $amount, string $currency): string
    {
        return md5($this->keys[$environment]['private_key'].$shopProcessId.'confirm'.$amount.$currency);
    }

    /**
     * Recorded calls, optionally of one operation.
     *
     * @return list<array{environment: string, operation: string, url: string, body: array<string, mixed>, response: array<string, mixed>|null}>
     */
    public function calls(?string $operation = null): array
    {
        return array_values(array_filter($this->calls, fn (array $call): bool => $operation === null || $call['operation'] === $operation));
    }

    public function assertCalled(string $operation, int $times = 1): void
    {
        Assert::assertCount($times, $this->calls($operation), 'Unexpected number of Bancard '.$operation.' calls.');
    }

    public function assertNotCalled(?string $operation = null): void
    {
        Assert::assertSame([], $this->calls($operation), 'Bancard was called.');
    }

    /** Bancard amount format: two decimals with a dot ("150000.00"). */
    public static function amount(Payment $payment): string
    {
        return number_format($payment->amount->amount, 2, '.', '');
    }

    /** @return array<string, list<string>> environment => base URLs without trailing slash */
    private function baseUrls(): array
    {
        $urls = [
            PaymentMethod::ENVIRONMENT_STAGING => [rtrim((string) config('cms.shop.bancard.urls.staging'), '/')],
            PaymentMethod::ENVIRONMENT_PRODUCTION => [rtrim((string) config('cms.shop.bancard.urls.production'), '/')],
        ];
        $override = config('cms.shop.bancard.staging_url_override');
        if (is_string($override) && $override !== '') {
            $urls[PaymentMethod::ENVIRONMENT_STAGING][] = rtrim($override, '/');
        }

        return $urls;
    }

    private function handle(string $environment, Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $operation = match ($path) {
            '/vpos/api/0.3/single_buy' => self::OPERATION_SINGLE_BUY,
            '/vpos/api/0.3/single_buy/confirmations' => self::OPERATION_CONFIRMATIONS,
            '/vpos/api/0.3/single_buy/rollback' => self::OPERATION_ROLLBACK,
            default => null,
        };
        $body = json_decode($request->body(), true);
        $call = ['environment' => $environment, 'operation' => $operation ?? $path, 'url' => $request->url(), 'body' => is_array($body) ? $body : [], 'response' => null];

        if ($operation === null || $request->method() !== 'POST') {
            $this->calls[] = $call;

            return Http::response('', 404);
        }
        if (($this->scenarios[$operation] ?? null) === self::TIMEOUT) {
            $this->calls[] = $call;

            return Factory::failedConnection('cURL error 28: Operation timed out (FakeBancard)');
        }

        [$status, $response] = $this->answer($environment, $operation, $body);
        $call['response'] = $response;
        $this->calls[] = $call;
        if ($operation === self::OPERATION_SINGLE_BUY && $this->scenarios[$operation] === self::TIMEOUT_AFTER_CREATE) {
            $this->scenarios[$operation] = self::APPROVED;

            return Factory::failedConnection('cURL error 28: Operation timed out after the buy was created (FakeBancard)');
        }

        return Http::response($response, $status);
    }

    /** @return array{int, array<string, mixed>} */
    private function answer(string $environment, string $operation, mixed $body): array
    {
        if (! is_array($body) || ! is_array($body['operation'] ?? null)) {
            return $this->error('InvalidJsonError', 'Invalid JSON.');
        }
        if (($body['public_key'] ?? null) !== $this->keys[$environment]['public_key']) {
            return $this->error('InvalidPublicKeyError', 'Invalid public key.');
        }
        $op = $body['operation'];
        $spid = $op['shop_process_id'] ?? null;
        if (! (is_int($spid) || (is_string($spid) && ctype_digit($spid))) || strlen((string) $spid) > 15 || ! is_string($op['token'] ?? null)) {
            return $this->error('InvalidOperationError', 'Invalid operation.');
        }
        $spid = (string) $spid;
        $private = $this->keys[$environment]['private_key'];

        return match ($operation) {
            self::OPERATION_SINGLE_BUY => $this->singleBuy($environment, $op, $spid, $private),
            self::OPERATION_CONFIRMATIONS => $this->confirmations($environment, $op, $spid, $private),
            self::OPERATION_ROLLBACK => $this->rollback($environment, $op, $spid, $private),
        };
    }

    /** @return array{int, array<string, mixed>} */
    private function singleBuy(string $environment, array $op, string $spid, string $private): array
    {
        $amount = $op['amount'] ?? null;
        $currency = $op['currency'] ?? null;
        $description = $op['description'] ?? null;
        $returnUrl = $op['return_url'] ?? null;
        $cancelUrl = $op['cancel_url'] ?? null;
        if (! is_string($amount) || preg_match('/^\d+\.\d{2}$/', $amount) !== 1 || $currency !== 'PYG'
            || ! is_string($description) || $description === '' || mb_strlen($description) > 100
            || ! is_string($returnUrl) || $returnUrl === '' || strlen($returnUrl) > 255
            || ($cancelUrl !== null && (! is_string($cancelUrl) || strlen($cancelUrl) > 255))) {
            return $this->error('InvalidOperationError', 'Invalid operation.');
        }
        if ($this->scenarios[self::OPERATION_SINGLE_BUY] === self::INVALID_TOKEN || ! hash_equals(md5($private.$spid.$amount.$currency), $op['token'])) {
            return $this->error('InvalidTokenError', 'Invalid token.');
        }

        if (isset($this->buys[$spid])) {
            return $this->error(self::DUPLICATE_KEY, 'The shop_process_id was already used.');
        }

        $processId = Str::random(20);
        $this->buys[$spid] = ['amount' => $amount, 'currency' => $currency, 'environment' => $environment, 'process_id' => $processId];

        return [200, ['status' => 'success', 'process_id' => $processId]];
    }

    /** @return array{int, array<string, mixed>} */
    private function confirmations(string $environment, array $op, string $spid, string $private): array
    {
        $scenario = $this->scenarios[self::OPERATION_CONFIRMATIONS];
        if ($scenario === self::INVALID_TOKEN || ! hash_equals(md5($private.$spid.'get_confirmation'), $op['token'])) {
            return $this->error('InvalidTokenError', 'Invalid token.');
        }
        $buy = $this->buys[$spid] ?? null;
        if ($buy === null || $buy['environment'] !== $environment) {
            return $this->error('BuyNotFoundError', 'Buy not found.');
        }
        if ($scenario === self::NOT_PAID) {
            return $this->error('PaymentNotFoundError', 'Payment not found.');
        }
        $approved = $scenario !== self::REJECTED;

        return [200, [
            'status' => 'success',
            'confirmation' => $this->confirmation($environment, $spid, $buy['amount'], $buy['currency'], $approved, null),
            'messages' => [['key' => $approved ? 'PaymentSuccess' : 'PaymentRejected', 'level' => 'info', 'dsc' => $approved ? 'Pago exitoso.' : 'Pago rechazado.']],
        ]];
    }

    /** @return array{int, array<string, mixed>} */
    private function rollback(string $environment, array $op, string $spid, string $private): array
    {
        $scenario = $this->scenarios[self::OPERATION_ROLLBACK];
        if ($scenario === self::INVALID_TOKEN || ! hash_equals(md5($private.$spid.'rollback0.00'), $op['token'])) {
            return $this->error('InvalidTokenError', 'Invalid token.');
        }
        $buy = $this->buys[$spid] ?? null;
        if ($buy === null || $buy['environment'] !== $environment) {
            return $this->error('BuyNotFoundError', 'Buy not found.');
        }
        if ($scenario === self::ALREADY_CONFIRMED) {
            return $this->error('TransactionAlreadyConfirmed', 'Transaction already confirmed.');
        }

        return [200, ['status' => 'success', 'messages' => [['key' => 'RollbackSuccessful', 'level' => 'info', 'dsc' => 'Rollback correcto.']]]];
    }

    /** @return array<string, mixed> */
    private function confirmation(string $environment, string $spid, string $amount, string $currency, bool $approved, ?string $responseCode): array
    {
        $code = $responseCode ?? ($approved ? '00' : self::REJECTED_RESPONSE_CODE);

        return [
            'token' => $this->confirmToken($environment, $spid, $amount, $currency),
            'shop_process_id' => (int) $spid,
            'response' => $approved ? 'S' : 'N',
            'response_details' => $approved ? 'Procesado Satisfactoriamente' : 'Transaccion denegada',
            'amount' => $amount,
            'currency' => $currency,
            'authorization_number' => $approved ? '123456' : null,
            'ticket_number' => $approved ? '123456789123456' : null,
            'response_code' => $code,
            'response_description' => $approved ? 'Transaccion aprobada' : 'Fondos insuficientes',
            'extended_response_description' => $approved ? null : 'IMPORTE DE LA TRN SUPERIOR AL SALDO',
            'security_information' => [
                'customer_ip' => '127.0.0.1',
                'card_source' => 'L',
                'card_country' => 'PARAGUAY',
                'version' => '0.3',
                'risk_index' => 0,
            ],
        ];
    }

    /** @return array{int, array<string, mixed>} */
    private function error(string $key, string $description): array
    {
        return [$this->errorStatus, ['status' => 'error', 'messages' => [['key' => $key, 'level' => 'error', 'dsc' => $description]]]];
    }
}
