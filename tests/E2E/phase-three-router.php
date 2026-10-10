<?php

use App\Services\Forms\Recaptcha;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

$app = require __DIR__.'/phase-zero-bootstrap.php';
// Clock injection exists only in the guarded isolated acceptance router.
$clock = getenv('PHASE0_STORAGE').'/phase-three-clock.json';
if (is_file($clock)) {
    Carbon::setTestNow(json_decode(file_get_contents($clock), true, flags: JSON_THROW_ON_ERROR)['now']);
    CarbonImmutable::setTestNow(Carbon::now());
}
// Stub only the external Google verification response; application anti-spam stays active.
Http::preventStrayRequests();
Http::fake([
    Recaptcha::VERIFY_URL => Http::response([
        'success' => true, 'action' => 'contact', 'hostname' => '127.0.0.1', 'score' => 0.9,
    ]),
]);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/storage/')) {
    $root = realpath(getenv('PHASE0_STORAGE').'/app/public');
    $file = realpath($root.'/'.substr($path, 9));
    if ($file && str_starts_with($file, $root.'/') && is_file($file)) {
        header('Content-Type: '.mime_content_type($file));
        readfile($file);

        return;
    }
}
if (str_starts_with($path, '/build/') && is_file(dirname(__DIR__, 2).'/public'.$path)) {
    return false;
}
$app->handleRequest(Request::capture());
