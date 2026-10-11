<?php

use App\Models\McpApiKey;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Tester\CommandTester;

$app = require __DIR__.'/api-key-only-bootstrap.php';
if (($argv[1] ?? '') === 'totp') {
    $user = User::where('email', 'key-only@example.test')->firstOrFail();
    echo app(\App\Services\Security\Totp::class)->code($user->two_factor_secret, intdiv(now()->timestamp, 30));
    exit;
}
if (($argv[1] ?? '') === 'recover') {
    $tester = new CommandTester(Artisan::all()['cms:user:reset-password']);
    $tester->setInputs(['Recovered-Test-Only-2026!']);
    if ($tester->execute(['email' => 'key-only@example.test']) !== 0) throw new RuntimeException('Recovery failed.');
    exit;
}
if (Schema::hasTable('users')) throw new RuntimeException('Fixture must be fresh.');
Artisan::call('migrate', ['--force' => true]);
$user = User::factory()->create(['email' => 'key-only@example.test', 'role' => 'admin', 'password' => 'Key-Only-Test-2026!']);
$keys = [];
foreach (['read-only', 'draft-only', 'full'] as $scope) $keys[$scope] = McpApiKey::issue($user, $scope, $scope, null);
$keys['expired'] = McpApiKey::issue($user, 'expired', 'full', now()->subMinute());
$keys['revoked'] = McpApiKey::issue($user, 'revoked', 'full', null);
$user->mcpApiKeys()->where('name', 'revoked')->update(['revoked_at' => now()]);
foreach (['oauth_access_tokens', 'oauth_authorization_codes'] as $table) {
    if (Schema::hasTable($table)) throw new RuntimeException('OAuth schema remains.');
}
file_put_contents(getenv('KEY_ONLY_STORAGE').'/manifest.json', json_encode($keys, JSON_THROW_ON_ERROR));
chmod(getenv('KEY_ONLY_STORAGE').'/manifest.json', 0600);
