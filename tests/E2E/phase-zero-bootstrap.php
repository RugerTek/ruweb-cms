<?php

use Illuminate\Contracts\Console\Kernel;

// Test-only bootstrap. Never loaded by public/index.php or production routes.
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$dbName = getenv('PHASE0_DATABASE');
$storage = getenv('PHASE0_STORAGE');
if (! preg_match('/^ruweb_phase0_[a-f0-9]{12}$/D', $dbName ?: '') || ! is_dir($storage ?: '')) {
    throw new RuntimeException('Dedicated phase-zero database and storage required.');
}
foreach (['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $dbName, 'DB_URL' => '', 'APP_ENV' => 'local', 'APP_URL' => 'http://127.0.0.1:8018', 'LARAVEL_STORAGE_PATH' => $storage, 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'phase0_session', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'log', 'MAIL_LOG_CHANNEL' => 'single', 'BREVO_API_KEY' => '', 'LOG_CHANNEL' => 'single', 'APP_CONFIG_CACHE' => $storage.'/config.php'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.connections.mysql.database') !== $dbName) {
    throw new RuntimeException('Database isolation failed.');
}

return $app;
