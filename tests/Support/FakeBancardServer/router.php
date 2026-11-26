<?php

/**
 * Fake Bancard vPOS 2.0 server for browser QA and local development (PLAN.md E3.6 3C1 and E3.7). Never used by the
 * PHPUnit suite (that uses tests/Support/FakeBancard.php with Http::fake) and never deployed.
 *
 *   php -S 127.0.0.1:8899 tests/Support/FakeBancardServer/router.php
 *
 * and on the site (APP_ENV=local only; App\Shop\Payments\Bancard\BancardClient ignores it anywhere else):
 *   BANCARD_STAGING_URL=http://127.0.0.1:8899
 * with the `bancard` payment method in Pruebas (staging) holding the keys below (Tests\Support\FakeBancard constants
 * unless FAKE_BANCARD_PUBLIC_KEY / FAKE_BANCARD_PRIVATE_KEY are set).
 *
 * What it imitates (manual 0.3.1 §3 and bancard-checkout-js 5.0.1):
 *   POST /vpos/api/0.3/single_buy                 → {"status":"success","process_id":"<20 chars>"}
 *   POST /vpos/api/0.3/single_buy/confirmations   → confirmation once decided; PaymentNotFoundError while pending
 *   POST /vpos/api/0.3/single_buy/rollback        → RollbackSuccessful / AlreadyRollbackedError / TransactionAlreadyConfirmed
 *   GET  /checkout/new?process_id=…               → iframe.html with "Aprobar" / "Rechazar"
 *   GET  /checkout/javascript/dist/bancard-checkout-<version>.js → bancard-checkout-fake.js (Bancard.Checkout.createForm)
 * Choosing in the iframe POSTs the webhook Bancard would send, with a valid md5 token, to the site's confirmation URL
 * (FAKE_BANCARD_WEBHOOK_URL, else <origin of return_url>/webhooks/bancard) and then postMessage()s
 * {message, details, return_url} to the page, like the real iframe.
 *
 * Fake-only helpers: GET /_fake/buys (state as JSON), POST /_fake/settle?shop_process_id=… (next rollback answers
 * TransactionAlreadyConfirmed), POST /_fake/reset. State lives in FAKE_BANCARD_STATE (default: a JSON file in the
 * system temp dir per port) because the built-in server runs every request in a fresh process.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3).'/vendor/autoload.php';

use Tests\Support\FakeBancard;

$publicKey = getenv('FAKE_BANCARD_PUBLIC_KEY') ?: FakeBancard::STAGING_PUBLIC_KEY;
$privateKey = getenv('FAKE_BANCARD_PRIVATE_KEY') ?: FakeBancard::STAGING_PRIVATE_KEY;
$stateFile = getenv('FAKE_BANCARD_STATE') ?: sys_get_temp_dir().DIRECTORY_SEPARATOR.'ruweb-fake-bancard-'.($_SERVER['SERVER_PORT'] ?? '8899').'.json';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$origin = 'http://'.($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8899');

/** @param array<string, mixed> $body */
function fake_json(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
}

/** @return array{int, array<string, mixed>} */
function fake_error(string $key, string $description): array
{
    return [400, ['status' => 'error', 'messages' => [['key' => $key, 'level' => 'error', 'dsc' => $description]]]];
}

/**
 * Read-modify-write of the state under an exclusive lock.
 *
 * @template T
 *
 * @param  callable(array<string, mixed>&): T  $change
 * @return T
 */
function fake_state(string $file, callable $change): mixed
{
    $handle = @fopen($file, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Fake Bancard cannot open its state file '.$file.' (set FAKE_BANCARD_STATE to a writable path).');
    }
    flock($handle, LOCK_EX);
    $raw = stream_get_contents($handle);
    $state = json_decode($raw === false || $raw === '' ? '{}' : $raw, true);
    $state = is_array($state) ? $state + ['buys' => []] : ['buys' => []];
    $result = $change($state);
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $result;
}

/** @param array<string, mixed> $buy @return array<string, mixed> */
function fake_confirmation(array $buy, string $privateKey, bool $approved): array
{
    $spid = (string) $buy['shop_process_id'];

    return [
        'token' => md5($privateKey.$spid.'confirm'.$buy['amount'].$buy['currency']),
        'shop_process_id' => (int) $spid,
        'response' => $approved ? 'S' : 'N',
        'response_details' => $approved ? 'Procesado Satisfactoriamente' : 'Transaccion denegada',
        'amount' => $buy['amount'],
        'currency' => $buy['currency'],
        'authorization_number' => $approved ? (string) random_int(100000, 999999) : null,
        'ticket_number' => $approved ? (string) random_int(100000000000000, 999999999999999) : null,
        'response_code' => $approved ? '00' : '51',
        'response_description' => $approved ? 'Transaccion aprobada' : 'Fondos insuficientes',
        'extended_response_description' => $approved ? null : 'IMPORTE DE LA TRN SUPERIOR AL SALDO',
        'security_information' => ['customer_ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 'card_source' => 'L', 'card_country' => 'PARAGUAY', 'version' => '0.3', 'risk_index' => 0],
    ];
}

/** @param array<string, mixed> $payload @return array{status: int, body: string} */
function fake_post_webhook(string $url, array $payload): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
        'content' => json_encode($payload),
        'timeout' => 60,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
            $status = (int) $match[1];
        }
    }

    return ['status' => $status, 'body' => substr($body === false ? 'no connection' : $body, 0, 500)];
}

/** @return array<string, mixed>|null the decoded API body when the public key and operation look right */
function fake_api_request(string $publicKey, ?array &$error): ?array
{
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (! is_array($body) || ! is_array($body['operation'] ?? null)) {
        $error = fake_error('InvalidJsonError', 'Invalid JSON.');

        return null;
    }
    if (($body['public_key'] ?? null) !== $publicKey) {
        $error = fake_error('InvalidPublicKeyError', 'Invalid public key.');

        return null;
    }
    $spid = $body['operation']['shop_process_id'] ?? null;
    if (! (is_int($spid) || (is_string($spid) && ctype_digit($spid))) || strlen((string) $spid) > 15 || ! is_string($body['operation']['token'] ?? null)) {
        $error = fake_error('InvalidOperationError', 'Invalid operation.');

        return null;
    }

    return $body['operation'];
}

// --- Bancard API ---------------------------------------------------------------------------------------------------

if ($method === 'POST' && $path === '/vpos/api/0.3/single_buy') {
    $error = null;
    $op = fake_api_request($publicKey, $error);
    if ($op === null) {
        fake_json(...$error);

        return true;
    }
    $spid = (string) $op['shop_process_id'];
    $amount = $op['amount'] ?? null;
    $currency = $op['currency'] ?? null;
    $description = $op['description'] ?? null;
    $returnUrl = $op['return_url'] ?? null;
    $cancelUrl = $op['cancel_url'] ?? null;
    if (! is_string($amount) || preg_match('/^\d+\.\d{2}$/', $amount) !== 1 || $currency !== 'PYG'
        || ! is_string($description) || $description === '' || mb_strlen($description) > 100
        || ! is_string($returnUrl) || $returnUrl === '' || strlen($returnUrl) > 255
        || ($cancelUrl !== null && (! is_string($cancelUrl) || strlen($cancelUrl) > 255))) {
        fake_json(...fake_error('InvalidOperationError', 'Invalid operation.'));

        return true;
    }
    if (! hash_equals(md5($privateKey.$spid.$amount.$currency), $op['token'])) {
        fake_json(...fake_error('InvalidTokenError', 'Invalid token.'));

        return true;
    }
    $answer = fake_state($stateFile, function (array &$state) use ($spid, $amount, $currency, $description, $returnUrl, $cancelUrl): array {
        foreach ($state['buys'] as $buy) {
            if ($buy['shop_process_id'] === $spid) {
                // Wording the official SDK looks for (api.py: "process has already been taken").
                return fake_error('InvalidOperationError', 'Shop process has already been taken.');
            }
        }
        $processId = bin2hex(random_bytes(10));
        $state['buys'][$processId] = [
            'shop_process_id' => $spid,
            'process_id' => $processId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $description,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'status' => 'pending',
            'settled' => false,
            'confirmation' => null,
            'webhook' => null,
            'created_at' => date(DATE_ATOM),
        ];

        return [200, ['status' => 'success', 'process_id' => $processId]];
    });
    fake_json(...$answer);

    return true;
}

if ($method === 'POST' && in_array($path, ['/vpos/api/0.3/single_buy/confirmations', '/vpos/api/0.3/single_buy/rollback'], true)) {
    $error = null;
    $op = fake_api_request($publicKey, $error);
    if ($op === null) {
        fake_json(...$error);

        return true;
    }
    $spid = (string) $op['shop_process_id'];
    $rollback = str_ends_with($path, '/rollback');
    $expected = $rollback ? md5($privateKey.$spid.'rollback0.00') : md5($privateKey.$spid.'get_confirmation');
    if (! hash_equals($expected, $op['token'])) {
        fake_json(...fake_error('InvalidTokenError', 'Invalid token.'));

        return true;
    }
    $answer = fake_state($stateFile, function (array &$state) use ($spid, $rollback): array {
        foreach ($state['buys'] as $processId => $buy) {
            if ($buy['shop_process_id'] !== $spid) {
                continue;
            }
            if ($rollback) {
                if ($buy['status'] === 'rolled_back') {
                    return fake_error('AlreadyRollbackedError', 'Already rollbacked.');
                }
                if ($buy['settled']) {
                    return fake_error('TransactionAlreadyConfirmed', 'Transaction already confirmed.');
                }
                $state['buys'][$processId]['status'] = 'rolled_back';

                return [200, ['status' => 'success', 'messages' => [['key' => 'RollbackSuccessful', 'level' => 'info', 'dsc' => 'Rollback correcto.']]]];
            }
            if (! in_array($buy['status'], ['approved', 'rejected'], true)) {
                return fake_error('PaymentNotFoundError', 'Payment not found.');
            }
            $approved = $buy['status'] === 'approved';

            return [200, [
                'status' => 'success',
                'confirmation' => $buy['confirmation'],
                'messages' => [['key' => $approved ? 'PaymentSuccess' : 'PaymentRejected', 'level' => 'info', 'dsc' => $approved ? 'Pago exitoso.' : 'Pago rechazado.']],
            ]];
        }

        return fake_error('BuyNotFoundError', 'Buy not found.');
    });
    fake_json(...$answer);

    return true;
}

// --- Checkout iframe and library -----------------------------------------------------------------------------------

if ($method === 'GET' && $path === '/checkout/new') {
    $processId = (string) ($_GET['process_id'] ?? '');
    $buy = fake_state($stateFile, fn (array &$state): ?array => $state['buys'][$processId] ?? null);
    $e = fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    if ($buy === null) {
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif">Fake Bancard: process_id desconocido.</p>';

        return true;
    }
    $amount = number_format((float) $buy['amount'], 0, ',', '.');
    echo strtr((string) file_get_contents(__DIR__.'/iframe.html'), [
        '{{PROCESS_ID}}' => $e($processId),
        '{{SHOP_PROCESS_ID}}' => $e($buy['shop_process_id']),
        '{{AMOUNT}}' => $e($amount),
        '{{CURRENCY}}' => $e($buy['currency']),
        '{{DESCRIPTION}}' => $e($buy['description']),
        '{{STATUS}}' => $e($buy['status']),
    ]);

    return true;
}

if ($method === 'POST' && $path === '/checkout/decide') {
    $input = json_decode((string) file_get_contents('php://input'), true);
    $processId = is_array($input) ? (string) ($input['process_id'] ?? '') : '';
    $approve = is_array($input) && ($input['decision'] ?? null) === 'approve';
    $buy = fake_state($stateFile, function (array &$state) use ($processId, $approve, $privateKey): ?array {
        $buy = $state['buys'][$processId] ?? null;
        if ($buy === null || $buy['status'] !== 'pending') {
            return null;
        }
        $buy['status'] = $approve ? 'approved' : 'rejected';
        $buy['confirmation'] = fake_confirmation($buy, $privateKey, $approve);
        $state['buys'][$processId] = $buy;

        return $buy;
    });
    if ($buy === null) {
        fake_json(409, ['error' => 'Unknown or already decided process_id.']);

        return true;
    }
    $parts = parse_url($buy['return_url']);
    $webhookUrl = getenv('FAKE_BANCARD_WEBHOOK_URL')
        ?: ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? 'localhost').(isset($parts['port']) ? ':'.$parts['port'] : '').'/webhooks/bancard';
    $webhook = fake_post_webhook($webhookUrl, ['operation' => $buy['confirmation']]);
    fake_state($stateFile, function (array &$state) use ($processId, $webhookUrl, $webhook): void {
        $state['buys'][$processId]['webhook'] = ['url' => $webhookUrl, 'sent_at' => date(DATE_ATOM)] + $webhook;
    });
    // What the real iframe posts to the page (bancard-checkout-js: {message, details, return_url}). The status values
    // are the fake's choice: only "payment_success" is documented (PLAN.md E3.2) and the site must not trust them.
    fake_json(200, [
        'message' => $approve ? 'payment_success' : 'payment_fail',
        'details' => $buy['confirmation']['response_description'],
        'return_url' => $approve || $buy['cancel_url'] === null ? $buy['return_url'] : $buy['cancel_url'],
        'webhook_status' => $webhook['status'],
    ]);

    return true;
}

if ($method === 'GET' && preg_match('#^/checkout/javascript/dist/bancard-checkout-\d+\.\d+\.\d+(-sandbox)?\.js$#', $path) === 1) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo str_replace('__FAKE_BANCARD_URL__', $origin, (string) file_get_contents(__DIR__.'/bancard-checkout-fake.js'));

    return true;
}

// --- Fake-only helpers ---------------------------------------------------------------------------------------------

if ($method === 'GET' && $path === '/_fake/buys') {
    fake_json(200, fake_state($stateFile, fn (array &$state): array => $state));

    return true;
}

if ($method === 'POST' && $path === '/_fake/settle') {
    $spid = (string) ($_GET['shop_process_id'] ?? '');
    $found = fake_state($stateFile, function (array &$state) use ($spid): bool {
        foreach ($state['buys'] as $processId => $buy) {
            if ($buy['shop_process_id'] === $spid) {
                $state['buys'][$processId]['settled'] = true;

                return true;
            }
        }

        return false;
    });
    fake_json($found ? 200 : 404, ['settled' => $found]);

    return true;
}

if ($method === 'POST' && $path === '/_fake/reset') {
    fake_state($stateFile, function (array &$state): void {
        $state = ['buys' => []];
    });
    fake_json(200, ['reset' => true]);

    return true;
}

if ($path === '/') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Fake Bancard vPOS 2.0 (Ruweb QA). Public key: {$publicKey}\nState: {$stateFile}\nSee tests/Support/FakeBancardServer/router.php.\n";

    return true;
}

fake_json(404, ['status' => 'error', 'messages' => [['key' => 'NotFound', 'level' => 'error', 'dsc' => 'Fake Bancard: unknown path '.$path]]]);

return true;
