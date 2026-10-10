<?php

use App\Models\McpApiKey;
use Illuminate\Support\Facades\DB;

$app = require __DIR__.'/phase-zero-bootstrap.php';
$manifest = getenv('PHASE0_STORAGE').'/manifest.json';
if (! is_file($manifest)) {
    throw new RuntimeException('Fixture manifest required for cleanup.');
}
if ((json_decode(file_get_contents($manifest), true)['database'] ?? null) !== getenv('PHASE0_DATABASE')) {
    throw new RuntimeException('Manifest/database mismatch.');
}
McpApiKey::query()->update(['revoked_at' => now()]);
$dbName = getenv('PHASE0_DATABASE');
DB::statement("DROP DATABASE `$dbName`");
unlink($manifest);
echo "Removed isolated fixture database and credentials: $dbName\n";
