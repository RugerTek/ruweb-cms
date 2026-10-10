<?php

// Guarded isolated fixture/control only. Functional changes use panel or MCP HTTP.
use App\Models\McpApiKey;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

$app = require __DIR__.'/phase-zero-bootstrap.php';
$storage = getenv('PHASE0_STORAGE');
$manifest = json_decode(file_get_contents($storage.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
if ($manifest['database'] !== getenv('PHASE0_DATABASE')) throw new RuntimeException('Fixture manifest mismatch.');
$action = $argv[1] ?? '';
if ($action === 'identity') {
    $admin = User::where('email', 'phase0@example.test')->firstOrFail();
    $admin->update(['can_manage_custom_code' => true]);
    $manifest['keys']['custom-code'] = McpApiKey::issue($admin, 'E2E08 explicit executable code', 'custom-code', now()->addHours(4));
    $denied = User::factory()->create(['role' => 'admin', 'can_manage_custom_code' => false]);
    $manifest['keys']['code-denied'] = McpApiKey::issue($denied, 'E2E08 scope without account permission', 'custom-code', now()->addHours(4));
    file_put_contents($storage.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    chmod($storage.'/manifest.json', 0600);
    echo "PASS explicit Custom Code account permission and separate test credential\n";
} elseif (in_array($action, ['disable', 'enable', 'status'], true)) {
    Artisan::call('cms:custom-code', ['action' => $action]);
    echo Artisan::output();
} else throw new InvalidArgumentException('Expected identity, disable, enable or status.');
