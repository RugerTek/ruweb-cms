<?php

namespace App\Services\Forms;

use App\Jobs\SendSubmissionNotification;
use App\Models\ContactSubmission;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Sends the "new submission" email when the form section on the page has
 * notifications enabled. Uses the site's Brevo mailer when a key exists,
 * otherwise the default mailer. Failures are logged, never shown to the
 * visitor: the submission is already stored. See docs/content/forms.md.
 */
class SubmissionNotifier
{
    public function notify(ContactSubmission $submission, ?Page $page, string $sectionKey = 'form'): bool
    {
        $section = $page?->sections()[$sectionKey] ?? [];
        $to = trim((string) ($section['notify_email'] ?? ''));

        if (! ($section['notify_enabled'] ?? false) || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (! Setting::get('mail_enabled')) {
            Log::info('Submission notification skipped: mail is disabled in settings.');

            return false;
        }

        $token = (string) Str::uuid();
        $reserved = ContactSubmission::whereKey($submission->id)->whereNull('notified_at')->whereIn('notification_status', ['skipped', 'failed'])->update([
            'notification_status' => 'queued', 'notification_token' => $token, 'notification_email' => $to,
            'notification_attempts' => 0, 'notification_claim' => null, 'notification_started_at' => null, 'notification_error' => null, 'notification_failed_at' => null,
        ]);
        if (! $reserved) {
            return false;
        }
        $enqueue = function () use ($submission, $token): bool {
            try {
                $connection = config('queue.default') === 'sync' ? 'database' : config('queue.default');
                Bus::dispatch((new SendSubmissionNotification($submission->id, $token))->onConnection($connection)->beforeCommit());

                return true;
            } catch (\Throwable $exception) {
                ContactSubmission::whereKey($submission->id)->where('notification_token', $token)->where('notification_status', 'queued')->update([
                    'notification_status' => 'failed', 'notification_failed_at' => now(), 'notification_error' => mb_substr($exception->getMessage(), 0, 1000),
                ]);
                Log::warning('Submission notification queue failed: '.$exception->getMessage());

                return false;
            }
        };
        if (DB::transactionLevel() > 0) {
            // Catch insertion failures at callback execution, after the reservation commits.
            DB::afterCommit($enqueue);

            return true;
        }

        return $enqueue();
    }

    public function mailer(): string
    {
        return trim((string) Setting::get('brevo_api_key')) !== '' || trim((string) config('mail.mailers.brevo.key')) !== '' ? 'brevo' : (string) config('mail.default');
    }
}
