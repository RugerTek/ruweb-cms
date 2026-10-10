<?php

namespace App\Jobs;

use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use App\Models\Setting;
use App\Services\Forms\SubmissionNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendSubmissionNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 60;

    public function __construct(public int $submissionId, public string $token) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(SubmissionNotifier $notifier): void
    {
        $claim = (string) Str::uuid();
        // Compare-and-swap prevents duplicate deliveries of the same queued generation.
        $claimed = ContactSubmission::whereKey($this->submissionId)->where('notification_token', $this->token)
            ->where(function ($query) {
                $query->whereIn('notification_status', ['queued', 'retrying'])
                    ->orWhere(function ($query) {
                        $query->where('notification_status', 'sending')->where('notification_started_at', '<', now()->subSeconds($this->timeout));
                    });
            })->update(['notification_status' => 'sending', 'notification_started_at' => now(), 'notification_claim' => $claim, 'notification_attempts' => DB::raw('notification_attempts + 1')]);
        if (! $claimed) {
            $active = ContactSubmission::whereKey($this->submissionId)->where('notification_token', $this->token)->first();
            if ($active?->notification_status === 'sending' && $active->notification_started_at) {
                // A retry arriving before the lease expires must remain queued.
                $remaining = $this->timeout - (now()->timestamp - $active->notification_started_at->timestamp);
                $this->release(max(1, $remaining + 1));
            }

            return;
        }
        $submission = ContactSubmission::findOrFail($this->submissionId);
        try {
            if (! Setting::get('mail_enabled')) {
                throw new \RuntimeException('Mail is disabled.');
            }
            Mail::mailer($notifier->mailer())->to($submission->notification_email)->send(new ContactSubmissionReceived($submission));
            ContactSubmission::whereKey($submission->id)->where('notification_token', $this->token)->where('notification_claim', $claim)->where('notification_status', 'sending')->update([
                'notification_status' => 'sent', 'notified_at' => now(), 'notification_error' => null, 'notification_failed_at' => null,
            ]);
        } catch (\Throwable $exception) {
            ContactSubmission::whereKey($submission->id)->where('notification_token', $this->token)->where('notification_claim', $claim)->where('notification_status', 'sending')->update([
                'notification_status' => 'retrying', 'notification_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        ContactSubmission::whereKey($this->submissionId)->where('notification_token', $this->token)->where(function ($query) {
            $query->whereIn('notification_status', ['queued', 'retrying'])
                ->orWhere(function ($query) {
                    $query->where('notification_status', 'sending')->where('notification_started_at', '<', now()->subSeconds($this->timeout));
                });
        })->update([
            'notification_status' => 'failed', 'notification_failed_at' => now(), 'notification_error' => mb_substr($exception?->getMessage() ?? 'Delivery failed.', 0, 1000),
        ]);
    }
}
