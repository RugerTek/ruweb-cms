<?php

namespace App\Services\Forms;

use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the "new submission" email when the form section on the page has
 * notifications enabled. Uses the site's Brevo mailer when a key exists,
 * otherwise the default mailer. Failures are logged, never shown to the
 * visitor: the submission is already stored. See docs/forms.md.
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

        try {
            Mail::mailer($this->mailer())->to($to)->send(new ContactSubmissionReceived($submission));
            $submission->forceFill(['notified_at' => now()])->save();

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Submission notification failed: '.$exception->getMessage());

            return false;
        }
    }

    public function mailer(): string
    {
        return Setting::get('brevo_api_key') ? 'brevo' : (string) config('mail.default');
    }
}
