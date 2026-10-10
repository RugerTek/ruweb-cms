<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class OAuthConcurrentExchangeTest extends TestCase
{
    public function test_concurrent_exchange_creates_only_one_token(): void
    {
        $directory = sys_get_temp_dir().'/ruweb-oauth-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $database = $directory.'/isolated.sqlite';
        $connection = new \PDO('sqlite:'.$database);
        $connection->exec('CREATE TABLE oauth_authorization_codes (id INTEGER PRIMARY KEY, code TEXT, user_id INTEGER, client_id TEXT, scope TEXT DEFAULT \'full\', redirect_uri TEXT, code_challenge TEXT, code_challenge_method TEXT, expires_at TEXT, used_at TEXT NULL, created_at TEXT)');
        $connection->exec('CREATE TABLE oauth_access_tokens (id INTEGER PRIMARY KEY, token TEXT, user_id INTEGER, client_id TEXT, scope TEXT DEFAULT \'full\', expires_at TEXT, revoked_at TEXT NULL, last_used_at TEXT NULL, created_at TEXT)');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', 'concurrent-verifier', true)), '+/', '-_'), '=');
        $statement = $connection->prepare('INSERT INTO oauth_authorization_codes (code,user_id,client_id,redirect_uri,code_challenge,code_challenge_method,expires_at) VALUES (?,1,?,?,?,?,?)');
        $statement->execute(['race-code', 'test-client', 'https://client.example/callback', $challenge, 'S256', now()->addMinute()->format('Y-m-d H:i:s')]);
        file_put_contents($directory.'/exchange.php', <<<'SCRIPT'
<?php
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[2], 'database.connections.sqlite.url' => null]);
\Illuminate\Support\Facades\DB::purge('sqlite');
\Illuminate\Support\Facades\DB::statement('PRAGMA busy_timeout = 5000');
file_put_contents($argv[3].'.ready', 'ready');
$deadline = microtime(true) + 5;
while (!file_exists($argv[4])) {
    if (microtime(true) > $deadline) exit(2);
    usleep(1000);
}
$request = \Illuminate\Http\Request::create('/token', 'POST', [
    'grant_type' => 'authorization_code', 'code' => 'race-code', 'client_id' => 'test-client',
    'redirect_uri' => 'https://client.example/callback', 'code_verifier' => 'concurrent-verifier',
]);
$response = app(\App\Http\Controllers\OAuthController::class)->token($request);
echo $response->getStatusCode();
SCRIPT);
        $processes = [];
        try {
            foreach (['first', 'second'] as $name) {
                $process = new Process([PHP_BINARY, $directory.'/exchange.php', base_path(), $database, $directory.'/'.$name, $directory.'/go'], base_path(), ['APP_ENV' => 'testing']);
                $process->setTimeout(15);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 5;
            while (! file_exists($directory.'/first.ready') || ! file_exists($directory.'/second.ready')) {
                $this->assertLessThan($deadline, microtime(true), 'Exchange processes did not initialize.');
                usleep(1000);
            }
            file_put_contents($directory.'/go', 'go');
            $statuses = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
                $this->assertMatchesRegularExpression('/^(200|400)$/', $process->getOutput(), $process->getOutput().$process->getErrorOutput());
                $statuses[] = (int) $process->getOutput();
            }
            sort($statuses);
            $this->assertSame([200, 400], $statuses);
            $this->assertSame(1, (int) $connection->query('SELECT COUNT(*) FROM oauth_access_tokens')->fetchColumn());
            $this->assertNotFalse($connection->query('SELECT used_at FROM oauth_authorization_codes')->fetchColumn());
        } finally {
            foreach ($processes as $process) if ($process->isRunning()) $process->stop();
            $connection = null;
            foreach (glob($directory.'/*') as $file) unlink($file);
            rmdir($directory);
        }
    }
}
