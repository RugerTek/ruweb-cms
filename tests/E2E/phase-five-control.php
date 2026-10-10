<?php

use App\Models\McpApiKey;
use App\Models\User;
use Carbon\CarbonImmutable;

// E2E05 setup and controlled clock only. Editorial changes use the real HTTP API.
$app = require __DIR__.'/phase-zero-bootstrap.php';
$storage = getenv('PHASE0_STORAGE');
$action = $argv[1] ?? '';
if ($action === 'identity') {
    $manifest = json_decode(file_get_contents($storage.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $admin = User::where('email', 'phase0@example.test')->firstOrFail();
    $other = User::factory()->create(['role' => 'admin']);
    $manifest['reviewer_id'] = $admin->id;
    $manifest['author_id'] = User::where('role', 'editor')->oldest('id')->firstOrFail()->id;
    $manifest['keys']['other'] = McpApiKey::issue($other, 'E2E05 unrelated reviewer', 'full', now()->addHour());
    file_put_contents($storage.'/manifest.json', json_encode($manifest));
    chmod($storage.'/manifest.json', 0600);
    echo "E2E05 identities ready\n";
} elseif ($action === 'clock') {
    $time = CarbonImmutable::parse($argv[2] ?? 'now')->toISOString();
    file_put_contents($storage.'/phase-three-clock.json', json_encode(['now' => $time]));
    echo $time;
} elseif ($action === 'reset-clock') {
    @unlink($storage.'/phase-three-clock.json');
} else {
    throw new InvalidArgumentException('Expected identity, clock or reset-clock.');
}
