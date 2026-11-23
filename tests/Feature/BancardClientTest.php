<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Shop\Payments\Bancard\BancardClient;
use App\Shop\Payments\Bancard\BancardCredentials;
use App\Shop\Payments\Bancard\BancardException;
use App\Shop\Payments\Bancard\BancardResponse;
use App\Shop\Payments\Bancard\BancardToken;
use App\ValueObjects\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Tests\Support\FakeBancard;
use Tests\TestCase;

/** Bancard vPOS 2.0 client (PLAN.md E3.1, track 3C1): tokens, amounts, request bodies, URLs, errors and secrecy. */
class BancardClientTest extends TestCase
{
    use RefreshDatabase;

    private const STAGING = 'https://vpos.infonet.com.py:8888';

    private const PRODUCTION = 'https://vpos.infonet.com.py';

    /** 40 characters, like a Bancard private key. */
    private const PRIVATE_KEY = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';

    private const RETURN_URL = 'https://tienda.example/productos/pedido/abc';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['cms.shop.bancard.staging_url_override' => null]);
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        });
    }

    public function test_tokens_match_vectors_computed_by_hand(): void
    {
        // Computed outside PHP (Python hashlib.md5) over the literal concatenations in the comments.
        // md5("abcdefghijklmnopqrstuvwxyz0123456789ABCD" . "1234567" . "150000.00" . "PYG")
        $this->assertSame('09cc5a38ca46e9562daa03569f97725c', BancardToken::singleBuy(self::PRIVATE_KEY, 1234567, '150000.00', 'PYG'));
        // md5(private . "1234567" . "confirm" . "150000.00" . "PYG")
        $this->assertSame('673096ee1fb1d7023176be1c2a1c716b', BancardToken::confirm(self::PRIVATE_KEY, 1234567, '150000.00', 'PYG'));
        // md5(private . "1234567" . "get_confirmation")
        $this->assertSame('d76e6f04b990c23e17907ce44717e089', BancardToken::getConfirmation(self::PRIVATE_KEY, 1234567));
        // md5(private . "1234567" . "rollback" . "0.00")
        $this->assertSame('17086efdca2664a782ff94811cde0f23', BancardToken::rollback(self::PRIVATE_KEY, 1234567));

        $this->assertSame(BancardToken::singleBuy(self::PRIVATE_KEY, 1234567, '150000.00', 'PYG'), BancardToken::singleBuy(self::PRIVATE_KEY, '1234567', '150000.00', 'PYG'));
        $this->assertTrue(BancardToken::matches('673096ee1fb1d7023176be1c2a1c716b', '673096EE1FB1D7023176BE1C2A1C716B'));
        $this->assertFalse(BancardToken::matches('673096ee1fb1d7023176be1c2a1c716b', '673096ee1fb1d7023176be1c2a1c716c'));
        $this->assertFalse(BancardToken::matches('673096ee1fb1d7023176be1c2a1c716b', null));
        $this->assertFalse(BancardToken::matches('673096ee1fb1d7023176be1c2a1c716b', ['673096ee1fb1d7023176be1c2a1c716b']));
    }

    public function test_amount_is_a_string_with_two_decimals_and_a_dot(): void
    {
        $this->assertSame('150000.00', BancardToken::amount(new Money(150000, 'PYG')));
        $this->assertSame('1.00', BancardToken::amount(new Money(1, 'PYG')));
        $this->assertSame('12500000.00', BancardToken::amount(new Money(12_500_000, 'PYG')), 'No thousands separator.');

        foreach ([new Money(100, 'USD'), new Money(0, 'PYG'), new Money(-5, 'PYG')] as $invalid) {
            try {
                BancardToken::amount($invalid);
                $this->fail('Amount accepted: '.$invalid->amount.' '.$invalid->currency);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_single_buy_sends_the_exact_json_and_returns_the_process_id(): void
    {
        $fake = $this->useFakeBancard();
        $credentials = $this->credentials();

        $response = (new BancardClient)->singleBuy($credentials, 7, new Money(150000, 'PYG'), '  Pedido ORD-2026-000001 — Ñandutí  ', self::RETURN_URL);

        $this->assertTrue($response->successful());
        $this->assertSame(20, strlen((string) $response->processId()));
        $this->assertSame(BancardClient::OPERATION_SINGLE_BUY, $response->operation);
        $fake->assertCalled(FakeBancard::OPERATION_SINGLE_BUY);

        $expected = '{"public_key":"'.FakeBancard::STAGING_PUBLIC_KEY.'","operation":{"token":"'.md5(FakeBancard::STAGING_PRIVATE_KEY.'7150000.00PYG').'",'
            .'"shop_process_id":7,"amount":"150000.00","currency":"PYG",'
            .'"description":"Pedido ORD-2026-000001 \u2014 \u00d1andut\u00ed","return_url":"https:\/\/tienda.example\/productos\/pedido\/abc"}}';
        Http::assertSent(fn (Request $request): bool => $request->url() === self::STAGING.'/vpos/api/0.3/single_buy'
            && $request->method() === 'POST'
            && $request->body() === $expected
            && $request->hasHeader('Content-Type', 'application/json'));

        (new BancardClient)->singleBuy($credentials, 8, new Money(99000, 'PYG'), str_repeat('á', 120), self::RETURN_URL, 'https://tienda.example/cancelado');
        $body = $fake->calls(FakeBancard::OPERATION_SINGLE_BUY)[1]['body'];
        $this->assertSame(['token', 'shop_process_id', 'amount', 'currency', 'description', 'return_url', 'cancel_url'], array_keys($body['operation']));
        $this->assertSame('https://tienda.example/cancelado', $body['operation']['cancel_url']);
        $this->assertSame(str_repeat('á', 100), $body['operation']['description'], 'Description trimmed to 100 characters.');
        $this->assertSame('99000.00', $body['operation']['amount']);
    }

    public function test_get_confirmation_and_rollback_send_the_exact_json(): void
    {
        $fake = $this->useFakeBancard();
        $client = new BancardClient;
        $credentials = $this->credentials();
        $client->singleBuy($credentials, 7, new Money(150000, 'PYG'), 'Pedido', self::RETURN_URL);

        $confirmation = $client->getConfirmation($credentials, 7);
        $this->assertTrue($confirmation->successful());
        $this->assertSame('00', $confirmation->confirmation()['response_code']);
        $this->assertTrue(BancardToken::matches(
            BancardToken::confirm($credentials->privateKey(), 7, '150000.00', 'PYG'),
            $confirmation->confirmation()['token'],
        ), 'The confirmation token checks with the confirm formula.');
        Http::assertSent(fn (Request $request): bool => $request->url() === self::STAGING.'/vpos/api/0.3/single_buy/confirmations'
            && $request->body() === '{"public_key":"'.FakeBancard::STAGING_PUBLIC_KEY.'","operation":{"token":"'.md5(FakeBancard::STAGING_PRIVATE_KEY.'7get_confirmation').'","shop_process_id":7}}');

        $fake->scenario(FakeBancard::OPERATION_CONFIRMATIONS, FakeBancard::NOT_PAID);
        $notPaid = $client->getConfirmation($credentials, 7);
        $this->assertFalse($notPaid->successful());
        $this->assertSame(BancardResponse::KEY_PAYMENT_NOT_FOUND, $notPaid->key());
        $this->assertNull($notPaid->confirmation());

        $fake->scenario(FakeBancard::OPERATION_CONFIRMATIONS, FakeBancard::REJECTED);
        $rejected = $client->getConfirmation($credentials, 7);
        $this->assertTrue($rejected->successful());
        $this->assertSame(['N', '51'], [$rejected->confirmation()['response'], $rejected->confirmation()['response_code']]);

        $this->assertSame(BancardResponse::KEY_BUY_NOT_FOUND, $client->getConfirmation($credentials, 999)->key());

        $rollback = $client->rollback($credentials, 7);
        $this->assertTrue($rollback->successful());
        $this->assertSame(BancardResponse::KEY_ROLLBACK_SUCCESSFUL, $rollback->key());
        Http::assertSent(fn (Request $request): bool => $request->url() === self::STAGING.'/vpos/api/0.3/single_buy/rollback'
            && $request->body() === '{"public_key":"'.FakeBancard::STAGING_PUBLIC_KEY.'","operation":{"token":"'.md5(FakeBancard::STAGING_PRIVATE_KEY.'7rollback0.00').'","shop_process_id":7}}');

        $fake->scenario(FakeBancard::OPERATION_ROLLBACK, FakeBancard::ALREADY_CONFIRMED);
        $settled = $client->rollback($credentials, 7);
        $this->assertSame(BancardResponse::KEY_TRANSACTION_ALREADY_CONFIRMED, $settled->key());
        try {
            $settled->orFail();
            $this->fail('orFail() accepted an error answer.');
        } catch (BancardException $exception) {
            $this->assertSame(BancardResponse::KEY_TRANSACTION_ALREADY_CONFIRMED, $exception->reason);
            $this->assertSame(BancardClient::OPERATION_ROLLBACK, $exception->operation);
            $this->assertSame($settled, $exception->response);
            $this->assertSame(__('checkout.bancard_errors.TransactionAlreadyConfirmed'), $exception->userMessage());
        }
        $this->assertSame($rollback, $rollback->orFail());
    }

    public function test_production_uses_its_url_and_keys(): void
    {
        $fake = $this->useFakeBancard(PaymentMethod::ENVIRONMENT_PRODUCTION);
        $credentials = BancardCredentials::fromMethod($this->bancardMethod());
        $this->assertSame(PaymentMethod::ENVIRONMENT_PRODUCTION, $credentials->environment);

        $this->assertTrue((new BancardClient)->singleBuy($credentials, 9, new Money(5000, 'PYG'), 'Pedido', self::RETURN_URL)->successful());
        $this->assertSame(self::PRODUCTION.'/vpos/api/0.3/single_buy', $fake->calls()[0]['url']);
        $this->assertSame(PaymentMethod::ENVIRONMENT_PRODUCTION, $fake->calls()[0]['environment']);
    }

    public function test_bancard_errors_are_mapped_by_key(): void
    {
        $fake = $this->useFakeBancard();
        $client = new BancardClient;

        app()->setLocale('es');
        $fake->scenario(FakeBancard::OPERATION_SINGLE_BUY, FakeBancard::INVALID_TOKEN);
        $invalidToken = $client->singleBuy($this->credentials(), 7, new Money(150000, 'PYG'), 'Pedido', self::RETURN_URL);
        $this->assertFalse($invalidToken->successful());
        $this->assertSame(BancardResponse::STATUS_ERROR, $invalidToken->status());
        $this->assertSame(400, $invalidToken->httpStatus);
        $this->assertSame(BancardResponse::KEY_INVALID_TOKEN, $invalidToken->key());
        $this->assertTrue($invalidToken->rejectsCredentials());
        $this->assertSame('La clave privada no coincide con la pública.', $invalidToken->errorMessage());

        $wrongPublic = new BancardCredentials(PaymentMethod::ENVIRONMENT_STAGING, FakeBancard::PRODUCTION_PUBLIC_KEY, FakeBancard::STAGING_PRIVATE_KEY);
        $answer = $client->getConfirmation($wrongPublic, 7);
        $this->assertSame(BancardResponse::KEY_INVALID_PUBLIC_KEY, $answer->key());
        $this->assertTrue($answer->rejectsCredentials());
        $this->assertSame(__('checkout.bancard_errors.InvalidPublicKeyError'), $answer->errorMessage());

        $fake->scenario(FakeBancard::OPERATION_CONFIRMATIONS, FakeBancard::APPROVED);
        $this->assertFalse($client->getConfirmation($this->credentials(), 404)->rejectsCredentials(), 'BuyNotFoundError means the keys were accepted.');

        app()->setLocale('en');
        $this->assertSame('The private key does not match the public key.', $invalidToken->errorMessage(), 'Translated in the admin language.');
    }

    public function test_unknown_keys_and_undocumented_answers(): void
    {
        Http::fake([
            self::STAGING.'/vpos/api/0.3/single_buy/confirmations' => Http::response(['status' => 'error', 'messages' => [['key' => 'BrandNewError', 'level' => 'error', 'dsc' => 'Algo nuevo de Bancard']]], 422),
            self::STAGING.'/vpos/api/0.3/single_buy/rollback' => Http::response('<html>Bad gateway</html>', 502),
            self::STAGING.'/vpos/api/0.3/single_buy' => Http::response(['status' => 'success'], 200),
        ]);
        $client = new BancardClient;

        $unknown = $client->getConfirmation($this->credentials(), 7);
        $this->assertSame('BrandNewError', $unknown->key());
        $this->assertSame('Algo nuevo de Bancard', $unknown->errorMessage(), 'Without a translation, Bancard\'s own description.');
        $this->assertSame(__('checkout.bancard_errors.unexpected'), (new BancardResponse('rollback', 400, ['status' => 'error']))->errorMessage());

        try {
            $client->rollback($this->credentials(), 7);
            $this->fail('An HTML answer was accepted.');
        } catch (BancardException $exception) {
            $this->assertSame(BancardException::UNEXPECTED, $exception->reason);
            $this->assertSame(__('checkout.bancard_errors.unexpected'), $exception->userMessage());
        }

        try {
            $client->singleBuy($this->credentials(), 7, new Money(150000, 'PYG'), 'Pedido', self::RETURN_URL);
            $this->fail('A success without process_id was accepted.');
        } catch (BancardException $exception) {
            $this->assertSame(BancardException::UNEXPECTED, $exception->reason);
        }
    }

    public function test_timeouts_throw_a_bancard_exception(): void
    {
        $fake = $this->useFakeBancard();
        app()->setLocale('es');
        $fake->scenario(FakeBancard::OPERATION_SINGLE_BUY, FakeBancard::TIMEOUT);

        try {
            (new BancardClient)->singleBuy($this->credentials(), 7, new Money(150000, 'PYG'), 'Pedido', self::RETURN_URL);
            $this->fail('A timeout did not throw.');
        } catch (BancardException $exception) {
            $this->assertSame(BancardException::TIMEOUT, $exception->reason);
            $this->assertTrue($exception->isTimeout());
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
            $this->assertInstanceOf(\RuntimeException::class, $exception, 'PaymentProvider::start() documents RuntimeException.');
            $this->assertSame('Bancard no respondió a tiempo.', $exception->userMessage());
        }
    }

    public function test_connection_failures_throw_bancard_exceptions(): void
    {
        Http::fake([self::STAGING.'/*' => Factory::failedConnection('cURL error 7: Failed to connect')]);
        try {
            (new BancardClient)->getConfirmation($this->credentials(), 7);
            $this->fail('A connection failure did not throw.');
        } catch (BancardException $exception) {
            $this->assertSame(BancardException::CONNECTION, $exception->reason);
            $this->assertFalse($exception->isTimeout());
            $this->assertSame(__('checkout.bancard_errors.connection'), $exception->userMessage());
        }
    }

    public function test_urls_per_environment_and_staging_override_only_in_local_and_testing(): void
    {
        $this->assertSame(self::STAGING, BancardClient::baseUrl('staging'));
        $this->assertSame(self::PRODUCTION, BancardClient::baseUrl('production'));
        $this->assertSame(self::STAGING.'/checkout/javascript/dist/bancard-checkout-5.0.1.js', BancardClient::checkoutScriptUrl('staging'));
        $this->assertSame(self::PRODUCTION.'/checkout/javascript/dist/bancard-checkout-5.0.1.js', BancardClient::checkoutScriptUrl('production'));
        $this->assertSame(self::STAGING, BancardClient::origin('staging'));

        config(['cms.shop.bancard.staging_url_override' => 'http://127.0.0.1:8899/']);
        $original = $this->app['env'];
        try {
            foreach (['testing', 'local'] as $env) {
                $this->app['env'] = $env;
                $this->assertSame('http://127.0.0.1:8899', BancardClient::baseUrl('staging'), $env);
                $this->assertSame('http://127.0.0.1:8899', BancardClient::origin('staging'), $env);
                $this->assertSame('http://127.0.0.1:8899/checkout/javascript/dist/bancard-checkout-5.0.1.js', BancardClient::checkoutScriptUrl('staging'), $env);
                $this->assertSame(self::PRODUCTION, BancardClient::baseUrl('production'), 'Production is never overridden ('.$env.').');
            }
            foreach (['production', 'staging'] as $env) {
                $this->app['env'] = $env;
                $this->assertSame(self::STAGING, BancardClient::baseUrl('staging'), 'Override ignored in '.$env.'.');
            }

            // A real site with the variable set still calls the real staging host (faked here).
            $this->app['env'] = 'production';
            $fake = $this->useFakeBancard();
            (new BancardClient)->getConfirmation($this->credentials(), 7);
            $this->assertSame(self::STAGING.'/vpos/api/0.3/single_buy/confirmations', $fake->calls()[0]['url']);

            $this->app['env'] = 'testing';
            (new BancardClient)->getConfirmation($this->credentials(), 7);
            $this->assertSame('http://127.0.0.1:8899/vpos/api/0.3/single_buy/confirmations', $fake->calls()[1]['url']);
        } finally {
            $this->app['env'] = $original;
        }

        $this->expectException(InvalidArgumentException::class);
        BancardClient::baseUrl('sandbox');
    }

    public function test_credentials_come_from_the_encrypted_method(): void
    {
        $method = $this->bancardMethod();
        $this->assertNull(BancardCredentials::fromMethod($method), 'Nothing saved yet.');

        $this->useFakeBancard();
        $method->refresh();
        $staging = BancardCredentials::fromMethod($method);
        $this->assertSame([PaymentMethod::ENVIRONMENT_STAGING, FakeBancard::STAGING_PUBLIC_KEY, FakeBancard::STAGING_PRIVATE_KEY], [$staging->environment, $staging->publicKey, $staging->privateKey()]);
        $this->assertSame(FakeBancard::PRODUCTION_PUBLIC_KEY, BancardCredentials::fromMethod($method, 'production')->publicKey);
        $this->assertSame('cdef', $staging->publicKeySuffix());
        $this->assertNull(BancardCredentials::fromMethod($method, 'sandbox'));

        $method->forceFill(['secrets' => ['staging' => ['public_key' => FakeBancard::STAGING_PUBLIC_KEY]]])->save();
        $this->assertNull(BancardCredentials::fromMethod($method->refresh()), 'Incomplete pair.');

        PaymentMethod::query()->whereKey($method->id)->update(['secrets' => 'not-encrypted']);
        $this->assertNull(BancardCredentials::fromMethod($method->refresh()), 'Unreadable secrets.');

        $this->expectException(InvalidArgumentException::class);
        new BancardCredentials('staging', FakeBancard::STAGING_PUBLIC_KEY, '');
    }

    public function test_invalid_requests_are_refused_before_calling_bancard(): void
    {
        $fake = $this->useFakeBancard();
        $client = new BancardClient;
        $money = new Money(150000, 'PYG');
        $cases = [
            'shop_process_id 0' => fn () => $client->singleBuy($this->credentials(), 0, $money, 'Pedido', self::RETURN_URL),
            'shop_process_id of 16 digits' => fn () => $client->getConfirmation($this->credentials(), Payment::MAX_SHOP_PROCESS_ID + 1),
            'rollback negative' => fn () => $client->rollback($this->credentials(), -1),
            'empty description' => fn () => $client->singleBuy($this->credentials(), 7, $money, '   ', self::RETURN_URL),
            'long return_url' => fn () => $client->singleBuy($this->credentials(), 7, $money, 'Pedido', 'https://tienda.example/'.str_repeat('a', 240)),
            'empty cancel_url' => fn () => $client->singleBuy($this->credentials(), 7, $money, 'Pedido', self::RETURN_URL, ''),
            'dollars' => fn () => $client->singleBuy($this->credentials(), 7, new Money(100, 'USD'), 'Pedido', self::RETURN_URL),
        ];
        foreach ($cases as $case => $call) {
            try {
                $call();
                $this->fail('Accepted: '.$case);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $fake->assertNotCalled();

        $this->assertSame(Payment::MAX_SHOP_PROCESS_ID, BancardClient::shopProcessId(new Payment(['shop_process_id' => Payment::MAX_SHOP_PROCESS_ID])));
        $this->expectException(InvalidArgumentException::class);
        BancardClient::shopProcessId(new Payment);
    }

    public function test_the_private_key_never_reaches_logs_exceptions_or_dumps(): void
    {
        $fake = $this->useFakeBancard();
        $client = new BancardClient;
        $credentials = $this->credentials();
        $exceptions = [];

        $client->singleBuy($credentials, 7, new Money(150000, 'PYG'), 'Pedido', self::RETURN_URL);
        $client->getConfirmation($credentials, 7);
        $client->rollback($credentials, 7);
        $fake->scenario(FakeBancard::OPERATION_CONFIRMATIONS, FakeBancard::INVALID_TOKEN);
        try {
            $client->getConfirmation($credentials, 7)->orFail();
        } catch (BancardException $exception) {
            $exceptions[] = $exception;
        }
        $fake->scenario(FakeBancard::OPERATION_ROLLBACK, FakeBancard::TIMEOUT);
        try {
            $client->rollback($credentials, 7);
        } catch (BancardException $exception) {
            $exceptions[] = $exception;
        }
        $this->assertCount(2, $exceptions);

        $this->logged = array_values(array_filter($this->logged, fn (string $line): bool => str_contains($line, 'Bancard call')));
        $this->assertCount(5, $this->logged, 'Every call is logged.');
        $this->assertStringContainsString('"operation":"single_buy"', $this->logged[0]);
        $this->assertStringContainsString('"key":"InvalidTokenError"', $this->logged[3]);
        $this->assertStringContainsString('"reason":"timeout"', $this->logged[4]);

        $dumps = $this->logged;
        foreach ($exceptions as $exception) {
            $dumps[] = $exception->getMessage();
            $dumps[] = (string) $exception;
            for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
                foreach ($current->getTrace() as $frame) {
                    foreach ($frame['args'] ?? [] as $argument) {
                        $this->assertNotInstanceOf(BancardCredentials::class, $argument, 'Credentials are #[SensitiveParameter] in traces.');
                        if (is_string($argument)) {
                            $dumps[] = $argument;
                        }
                    }
                }
            }
            $dumps[] = $exception->userMessage();
        }
        $dumps[] = print_r($credentials, true);
        ob_start();
        var_dump($credentials);
        $dumps[] = (string) ob_get_clean();
        $dumps[] = json_encode($credentials);
        foreach ($dumps as $dump) {
            $this->assertStringNotContainsString(FakeBancard::STAGING_PRIVATE_KEY, $dump);
        }
        foreach ($this->logged as $line) {
            $this->assertStringNotContainsString(FakeBancard::STAGING_PUBLIC_KEY, $line, 'Logs do not carry the public key either.');
            $this->assertStringNotContainsString('"token"', $line, 'Logs do not carry tokens.');
        }

        $this->expectException(LogicException::class);
        serialize($credentials);
    }

    private function bancardMethod(): PaymentMethod
    {
        return PaymentMethod::query()->where('key', 'bancard')->firstOrFail();
    }

    private function credentials(): BancardCredentials
    {
        return new BancardCredentials(PaymentMethod::ENVIRONMENT_STAGING, FakeBancard::STAGING_PUBLIC_KEY, FakeBancard::STAGING_PRIVATE_KEY);
    }
}
