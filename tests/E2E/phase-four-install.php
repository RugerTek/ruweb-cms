<?php

// Execute the real interactive installer with answers scoped to a disposable checkout.
use App\Models\Media;
use App\Models\Page;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;

$app = require __DIR__.'/phase-zero-bootstrap.php';
$database = getenv('PHASE0_DATABASE');
$config = config('database.connections.mysql');
$config['database'] = null;
config(['database.connections.phase4_guard' => $config]);
if (DB::connection('phase4_guard')->select('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?', [$database])) {
    throw new RuntimeException('Installer acceptance requires a never-used database.');
}
$command = Artisan::all()['cms:install'];
$tester = new CommandTester($command);
$tester->setInputs([$config['host'], (string) $config['port'], $database, $config['username'], $config['password'] ?? '', 'es', 'Phase Four First Admin', 'phase4-admin@example.test', 'Phase4-Admin-Only-2026!']);
$status = $tester->execute([]);
if ($status !== 0 || User::count() !== 1 || User::first()->role !== 'admin') {
    throw new RuntimeException('Real installer did not provision exactly one administrator.');
}
$manifest = ['database' => $database, 'installed_by' => 'cms:install', 'admin_email' => 'phase4-admin@example.test'];
file_put_contents(getenv('PHASE0_STORAGE').'/manifest.json', json_encode($manifest));
chmod(getenv('PHASE0_STORAGE').'/manifest.json', 0600);
file_put_contents(getenv('PHASE0_STORAGE').'/phase-four-install-results.json', json_encode(['real_installer' => true, 'first_admin_count' => 1, 'admin_role' => 'admin', 'placeholder_count' => Media::whereNotNull('placeholder_key')->count(), 'page_count' => Page::count()], JSON_PRETTY_PRINT));
echo "PASS real cms:install and first administrator\n";
