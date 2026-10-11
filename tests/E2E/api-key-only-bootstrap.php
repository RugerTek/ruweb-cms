<?php

// Test-only bootstrap: fresh SQLite/storage, never the site's database or sessions.
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 2);
$storage = getenv('KEY_ONLY_STORAGE');
if (! $storage || ! str_starts_with($storage, sys_get_temp_dir().'/ruweb-key-only-') || ! is_dir($storage)) {
    throw new RuntimeException('Isolated key-only fixture required.');
}
require $root.'/vendor/autoload.php';
foreach (['APP_ENV' => 'local', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $storage.'/database.sqlite',
    'DB_URL' => '', 'LARAVEL_STORAGE_PATH' => $storage, 'APP_CONFIG_CACHE' => $storage.'/config.php',
    'APP_ROUTES_CACHE' => $storage.'/routes.php', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'key_only_session',
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'single'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['cms.admin_profile' => getenv('KEY_ONLY_PROFILE')]);
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $storage.'/database.sqlite') {
    throw new RuntimeException('Database isolation failed.');
}

return $app;
