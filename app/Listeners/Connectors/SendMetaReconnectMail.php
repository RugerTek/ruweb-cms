<?php

namespace App\Listeners\Connectors;

use App\Connectors\Meta\Connect\MetaConnection;
use App\Events\Connectors\MetaConnectionLost;
use App\Mail\Connectors\MetaReconnectNeeded;
use App\Models\Setting;
use App\Models\User;
use App\Services\Forms\SubmissionNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * «Volvé a conectar» email (plan-meta-v2, track 3c), registered by Laravel's listener discovery (handle()): when the
 * Meta connection goes to needs_reconnect, the site's administrators — or the contact email when no administrator has
 * a valid address — get ONE email with the reason and a «Volver a conectar» button to the Meta connector.
 *
 * Once per connection: MetaConnectionStore dispatches the event only on the transition, and a cache marker keyed by
 * the connection (business + connection date, which changes when the client connects again) is claimed atomically
 * before queueing, so a second loss of the same connection never mails again; after reconnecting the next loss does.
 * Only while `mail_enabled` is on (otherwise only a log line). Queued through the same mailer as the order emails
 * (SubmissionNotifier::mailer()). A failure is logged, never thrown: the needs_reconnect state is already stored.
 */
final class SendMetaReconnectMail
{
    /** How long the «already mailed» marker lasts. */
    private const MARKER_DAYS = 365;

    public function __construct(private readonly SubmissionNotifier $notifier) {}

    public function handle(MetaConnectionLost $event): void
    {
        $connection = $event->connection;
        $context = ['connector' => 'meta', 'business_id' => $connection->businessId];
        Log::warning('Meta connection needs to be connected again.', $context + ['reason' => $event->reason]);

        if (! Setting::get('mail_enabled')) {
            Log::info('Meta reconnect mail skipped: mail is disabled in settings.', $context);

            return;
        }
        $recipients = self::recipients();
        if ($recipients === []) {
            Log::warning('Meta reconnect mail skipped: no administrator or contact email.', $context);

            return;
        }

        $marker = self::marker($connection);
        if (! Cache::add($marker, now()->timestamp, now()->addDays(self::MARKER_DAYS))) {
            return;
        }
        try {
            foreach ($recipients as $to) {
                Mail::mailer($this->notifier->mailer())->to($to)->queue(new MetaReconnectNeeded($event->reason, $connection->catalogName));
            }
        } catch (Throwable $exception) {
            Cache::forget($marker);
            Log::warning('Meta reconnect mail could not be queued: '.$exception->getMessage(), $context);
        }
    }

    /** Cache key of «this connection was already mailed»: a new connection (reconnecting) gets a new one. */
    public static function marker(MetaConnection $connection): string
    {
        return 'connectors:meta-reconnect-mail:'.sha1($connection->businessId.'|'.$connection->connectedAt?->format(DATE_ATOM));
    }

    /**
     * Valid emails of the administrators, else the site's contact email (also used by MetaUnreachableStreak).
     *
     * @return list<string>
     */
    public static function recipients(): array
    {
        $emails = User::query()->where('role', 'admin')->clientAccounts()->orderBy('id')->pluck('email')
            ->map(fn (mixed $email): string => trim((string) $email))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()->values()->all();
        if ($emails === []) {
            $contact = trim((string) Setting::get('contact_email'));
            if (filter_var($contact, FILTER_VALIDATE_EMAIL) !== false) {
                $emails = [$contact];
            }
        }

        return $emails;
    }
}
