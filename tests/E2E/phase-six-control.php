<?php

// E2E06 setup/control and assertions only; functional mutations use browser or MCP HTTP.
use App\Models\ContactSubmission;
use App\Models\McpApiKey;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

$app = require __DIR__.'/phase-zero-bootstrap.php';
$storage = getenv('PHASE0_STORAGE');
$manifest = json_decode(file_get_contents($storage.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
if ($manifest['database'] !== getenv('PHASE0_DATABASE')) {
    throw new RuntimeException('Fixture manifest mismatch.');
}
$action = $argv[1] ?? '';
if ($action === 'identity') {
    $admin = User::where('email', 'phase0@example.test')->firstOrFail();
    $manifest['admin_id'] = $admin->id;
    $viewer = User::factory()->create(['role' => 'viewer']);
    $manifest['keys']['viewer'] = McpApiKey::issue($viewer, 'E2E06 viewer', 'full', now()->addHour());
    $manifest['keys']['expired'] = McpApiKey::issue($admin, 'E2E06 expired', 'full', now()->subMinute());
    file_put_contents($storage.'/manifest.json', json_encode($manifest));
    chmod($storage.'/manifest.json', 0600);
} elseif (in_array($action, ['fail-worker', 'deliver-worker'], true)) {
    $fixture = json_decode(file_get_contents($storage.'/phase-six-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $id = $fixture['id'];
    // Swap only the worker's mail transport. No queue/job/submission record is edited by the control.
    if ($action === 'fail-worker') {
        Mail::extend('phase6fault', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new RuntimeException('E2E06 controlled transport failure');
            }

            public function __toString(): string
            {
                return 'phase6fault';
            }
        });
        config(['mail.default' => 'phase6fault', 'mail.mailers.phase6fault' => ['transport' => 'phase6fault']]);
        $start = CarbonImmutable::now();
        for ($attempt = 1; $attempt <= 4; $attempt++) {
            Carbon::setTestNow($start->addMinutes(($attempt - 1) * 15));
            CarbonImmutable::setTestNow(Carbon::now());
            Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);
            $record = ContactSubmission::findOrFail($id);
            if ($record->notification_attempts !== $attempt || $record->notified_at !== null) {
                throw new RuntimeException('Expected real failed attempt '.$attempt);
            }
            if ($attempt < 4 && $record->notification_status !== 'retrying') {
                throw new RuntimeException('Retry backoff state missing.');
            }
        }
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        if ($record->notification_status !== 'failed' || ! $record->notification_failed_at || DB::table('failed_jobs')->count() !== 1 || DB::table('jobs')->count() !== 0) {
            throw new RuntimeException('Queue did not exhaust actual job retry policy.');
        }
        echo "PASS real worker exhausts four transport failures and retains submission\n";
    } else {
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);
        $record = ContactSubmission::findOrFail($id);
        if ($record->notification_status !== 'sent' || ! $record->notified_at || $record->notification_attempts !== 1 || DB::table('jobs')->count() !== 0) {
            throw new RuntimeException('Recovered queue did not deliver once.');
        }
        $log = file_get_contents($storage.'/logs/laravel.log');
        if (substr_count($log, 'To: inbox@example.test') !== 1 || ! str_contains($log, 'E2E06 delivery body')) {
            throw new RuntimeException('Expected exactly one logged delivery.');
        }
        echo "PASS recovered real worker delivers exactly once to local mail log\n";
    }
} else {
    throw new InvalidArgumentException('Expected identity, fail-worker or deliver-worker.');
}
