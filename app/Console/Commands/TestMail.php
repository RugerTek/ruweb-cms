<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Mail\MailSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TestMail extends Command
{
    protected $signature = 'mail:test {email : Destination email address}';

    protected $description = 'Send a test email with the provider chosen in Admin > Email (Brevo or SMTP).';

    public function handle(MailSettings $mail): int
    {
        $to = $this->argument('email');

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email: {$to}");

            return self::FAILURE;
        }

        if (! $mail->configured()) {
            $this->error($mail->provider() === MailSettings::PROVIDER_SMTP
                ? 'SMTP is not complete: set the server and port in Admin > Email.'
                : 'No Brevo API key configured (Admin > Email or BREVO_API_KEY env).');

            return self::FAILURE;
        }

        $mail->apply();
        $fromAddress = Setting::get('mail_from_address') ?: config('mail.from.address');
        $fromName = Setting::get('mail_from_name') ?: config('mail.from.name');
        $provider = $mail->provider() === MailSettings::PROVIDER_SMTP ? 'SMTP ('.$mail->smtpHost().':'.$mail->smtpPort().')' : 'Brevo';

        $this->info("Sending test email via {$provider}...");
        $this->line("  From: {$fromName} <{$fromAddress}>");
        $this->line("  To:   {$to}");

        try {
            Mail::mailer($mail->mailer())->raw(
                'This is a test email sent from '.config('app.name')." via {$provider}.\n\nIf you received this, your setup is working.",
                function ($message) use ($to, $fromAddress, $fromName) {
                    $message->to($to)
                        ->from($fromAddress, $fromName)
                        ->subject('['.config('app.name').'] Test email');
                }
            );
            $mail->clearError();

            $this->info('✓ Email sent successfully.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $mail->recordError($e);
            $this->error('✗ Failed: '.$mail->scrub($e->getMessage()));

            return self::FAILURE;
        }
    }
}
