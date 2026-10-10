<?php

// Test-only, isolated bootstrap refuses any non-fixture schema/storage.
try {
    $app = require __DIR__.'/phase-zero-bootstrap.php';
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
use App\Models\Media;
use App\Models\Page;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$action = $argv[1] ?? '';
if ($action === 'discovery') {
    echo json_encode(app(AdminCapabilities::class)->discovery());
} elseif ($action === 'cache') {
    foreach (['config:clear', 'route:clear', 'config:cache', 'route:cache'] as $command) {
        if (Artisan::call($command) !== 0) {
            throw new RuntimeException($command.' failed: '.Artisan::output());
        }
    }
    echo "Deployment caches regenerated\n";
} elseif ($action === 'conventions') {
    $status = Artisan::call('cms:check-conventions');
    echo Artisan::output();
    exit($status);
} elseif ($action === 'media') {
    echo Media::where('placeholder_key', 'square')->firstOrFail()->id;
} elseif ($action === 'unlock') {
    DB::table('content_edit_locks')->delete();
    echo "Isolated editor leases cleared\n";
} elseif ($action === 'future') {
    Page::create(['title' => 'Future profile fixture', 'slug' => 'phase2-future', 'template_key' => 'default', 'status' => 'published', 'published_at' => now()->addDay()]);
    echo "Future fixture ready\n";
} else {
    throw new InvalidArgumentException('Expected discovery, cache, conventions, media, unlock or future.');
}
