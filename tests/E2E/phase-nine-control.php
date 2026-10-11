<?php

// Guarded E2E identity recovery and asynchronous status assertions, never a production endpoint.
use App\Models\ContactSubmission;
use App\Models\OauthAccessToken;
use App\Models\OauthAuthorizationCode;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;

$app = require __DIR__.'/phase-zero-bootstrap.php';
$storage = getenv('PHASE0_STORAGE');
$manifest = json_decode(file_get_contents($storage.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
if ($manifest['database'] !== getenv('PHASE0_DATABASE')) throw new RuntimeException('Manifest mismatch.');
$profile = getenv('PHASE9_PROFILE');
if (! in_array($profile, ['full', 'essential'], true)) throw new RuntimeException('Profile required.');
$action = $argv[1] ?? '';
if ($action === 'recover') {
    $fixture = json_decode(file_get_contents($storage.'/phase-nine-identity-'.$profile.'.json'), true, flags: JSON_THROW_ON_ERROR);
    $user = User::where('email', $fixture['email'])->firstOrFail();
    $tester = new CommandTester(Artisan::all()['cms:user:reset-password']);
    $tester->setInputs(['Phase9-Recovered-Only-2026!']);
    if ($tester->execute(['email' => $user->email]) !== 0) throw new RuntimeException('Recovery command failed.');
    if ($user->mcpApiKeys()->whereNull('revoked_at')->exists()
        || OauthAccessToken::where('user_id', $user->id)->whereNull('revoked_at')->exists()
        || OauthAuthorizationCode::where('user_id', $user->id)->whereNull('used_at')->exists()
        || DB::table('admin_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->exists()) {
        throw new RuntimeException('Recovery left account connections live.');
    }
    echo "PASS real CLI recovery invalidates named keys, OAuth codes/tokens and sessions\n";
} elseif ($action === 'worker') {
    Artisan::call('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);
    $result = json_decode(file_get_contents($storage.'/phase-nine-http-'.$profile.'-results.json'), true, flags: JSON_THROW_ON_ERROR);
    $record = ContactSubmission::findOrFail($result['submission_id']);
    if ($record->notification_status !== 'sent' || $record->notification_attempts !== 1 || ! $record->notified_at) throw new RuntimeException('Async worker failed.');
    echo "PASS asynchronous local mail completes with one delivery attempt\n";
} elseif ($action === 'verify') {
    $result = json_decode(file_get_contents($storage.'/phase-nine-http-'.$profile.'-results.json'), true, flags: JSON_THROW_ON_ERROR);
    $record = ContactSubmission::findOrFail($result['submission_id']);
    if ($record->notes()->where('body', 'Phase nine interrupted response')->count() !== 1) throw new RuntimeException('Retry duplicated note.');
    if ($record->notification_status !== 'sent' || $record->notification_attempts !== 1) throw new RuntimeException('Unexpected delivery state.');
    echo "PASS real MySQL confirms one note and one completed mail generation\n";
} else throw new InvalidArgumentException('Expected recover, worker or verify.');
