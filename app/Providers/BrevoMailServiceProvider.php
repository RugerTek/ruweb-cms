<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use App\Models\Setting;
use App\Services\Mail\MailSettings;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Outgoing email from Admin > Email (docs/operations/email.md): registers the Brevo API transport, applies the
 * panel settings (provider Brevo or SMTP, sender) through MailSettings, and keeps MailSettings' last error current:
 * a queued email or contact notification that fails records it, any email that goes out clears it.
 */
class BrevoMailServiceProvider extends ServiceProvider
{
    /** Queued jobs that deliver email: their failures are the site's email failures. */
    private const MAIL_JOBS = ['Illuminate\Mail\SendQueuedMailable', 'App\Jobs\SendSubmissionNotification'];

    public function boot(): void
    {
        Mail::extend('brevo', function (array $config = []) {
            $apiKey = $this->resolveApiKey($config);

            return new BrevoTransport($apiKey);
        });

        $this->applyRuntimeMailConfig();

        Event::listen(MessageSent::class, fn () => app(MailSettings::class)->clearError());
        Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
            if (in_array($event->job->resolveName(), self::MAIL_JOBS, true)) {
                app(MailSettings::class)->recordError($event->exception);
            }
        });
    }

    /**
     * Pulls the provider (Brevo or SMTP), its credentials and the sender from DB settings so the admin UI can drive
     * email config without touching .env. Falls back to env values.
     */
    protected function applyRuntimeMailConfig(): void
    {
        app(MailSettings::class)->apply();
    }

    protected function resolveApiKey(array $config): string
    {
        try {
            if (Schema::hasTable('settings')) {
                $key = trim((string) Setting::get('brevo_api_key'));
                if ($key !== '') {
                    return $key;
                }
            }
        } catch (Throwable) {
            // fall through
        }

        return (string) ($config['key'] ?? env('BREVO_API_KEY', ''));
    }
}
