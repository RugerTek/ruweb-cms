<?php

// Shared by the OLD and candidate disposable artifacts; never a production entrypoint.
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$db = getenv('PHASE0_DATABASE');
$storage = getenv('PHASE0_STORAGE');
$port = getenv('PHASE10_PORT');
if (! preg_match('/^ruweb_phase0_[a-f0-9]{12}$/D', $db ?: '') || ! is_dir($storage ?: '') || ! in_array($port, ['8018', '8019'], true) || ! str_contains(realpath($root), '/ruweb-phase10-')) {
    throw new RuntimeException('Phase ten isolation guard refused.');
}
foreach (['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $db, 'DB_URL' => '', 'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:'.$port, 'LARAVEL_STORAGE_PATH' => $storage, 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'phase10_'.$port, 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'log', 'MAIL_LOG_CHANNEL' => 'single', 'BREVO_API_KEY' => '', 'LOG_CHANNEL' => 'single', 'APP_CONFIG_CACHE' => $storage.'/config.php', 'APP_ROUTES_CACHE' => $storage.'/routes.php'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== $db || config('app.url') !== 'http://127.0.0.1:'.$port) {
    throw new RuntimeException('Phase ten isolation failed.');
}

return $app;
