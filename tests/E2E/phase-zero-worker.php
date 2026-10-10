<?php

use App\Models\ContactSubmission;
use Illuminate\Support\Facades\Artisan;

$app = require __DIR__.'/phase-zero-bootstrap.php';
Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);
$submission = ContactSubmission::where('email', 'visitor@example.test')->latest()->firstOrFail();
if ($submission->notification_status !== 'sent' || ! $submission->notified_at) {
    throw new RuntimeException('Notification not delivered to test mail log: '.$submission->notification_status);
}
$log = file_get_contents(getenv('PHASE0_STORAGE').'/logs/laravel.log');
if (! str_contains($log, 'inbox@example.test') || ! str_contains($log, 'Phase zero delivery verification')) {
    throw new RuntimeException('Test mailbox evidence missing.');
}
echo "PASS real queue delivered form notification to local test mail log\n";
