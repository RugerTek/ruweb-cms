<?php

namespace App\Services\Connectors\Meta;

use App\Connectors\Meta\Connect\MetaConnection;
use App\Listeners\Connectors\SendMetaReconnectMail;
use App\Mail\Connectors\MetaNotResponding;
use App\Services\Forms\SubmissionNotifier;
use App\Services\Mail\MailSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Consecutive days on which the daily health check (MetaHealthCheckCommand) could not reach Meta, per connection
 * (business + connection date: reconnecting starts over). A day without a run or a day on which Meta answered breaks
 * the streak. From WARN_DAYS on, the Meta detail shows «Meta no responde desde hace N días» and the administrators get
 * ONE email per streak — but the connection is kept: only an explicit rejection from Meta (190/102, 100/33) asks to
 * connect again, and everything resumes by itself as soon as Meta answers.
 */
final class MetaUnreachableStreak
{
    /** Consecutive days without Meta before the visible warning and the email. */
    public const WARN_DAYS = 3;

    private const DAYS_KEY = 'meta_health.failed_days.';

    private const MAIL_KEY = 'meta_health.unreachable_mail.';

    public function __construct(private readonly SubmissionNotifier $notifier) {}

    /** Counts today as a day without Meta and answers the length of the current streak (≥ 1). */
    public function fail(MetaConnection $connection): int
    {
        $days = $this->current($connection);
        $today = now()->toDateString();
        if (! in_array($today, $days, true)) {
            $days[] = $today;
        }
        Cache::forever(self::DAYS_KEY.$this->id($connection), $days);

        return count($days);
    }

    /** Length of the current streak (0 when Meta answered or the last failure is older than yesterday). */
    public function days(MetaConnection $connection): int
    {
        return $connection->isConnected() ? count($this->current($connection)) : 0;
    }

    /** True when the panel has to show the «Meta no responde» warning. */
    public function warns(MetaConnection $connection): bool
    {
        return ! $connection->needsReconnect() && $this->days($connection) >= self::WARN_DAYS;
    }

    /** Meta answered (or the connection changed state): the streak and its email marker start over. */
    public function reset(MetaConnection $connection): void
    {
        Cache::forget(self::DAYS_KEY.$this->id($connection));
        Cache::forget(self::MAIL_KEY.$this->id($connection));
    }

    /**
     * Emails the administrators (or the contact email) once per streak. Only while `mail_enabled` is on; a failure is
     * logged, never thrown. Answers whether the email was queued now.
     */
    public function mailOnce(MetaConnection $connection, int $days): bool
    {
        $context = ['connector' => 'meta', 'business_id' => $connection->businessId, 'days' => $days];
        if (! app(MailSettings::class)->enabled()) {
            Log::info('Meta not responding mail skipped: mail is disabled in settings.', $context);

            return false;
        }
        $recipients = SendMetaReconnectMail::recipients();
        if ($recipients === []) {
            Log::warning('Meta not responding mail skipped: no administrator or contact email.', $context);

            return false;
        }
        $marker = self::MAIL_KEY.$this->id($connection);
        if (! Cache::add($marker, now()->timestamp, now()->addYear())) {
            return false;
        }
        try {
            foreach ($recipients as $to) {
                Mail::mailer($this->notifier->mailer())->to($to)->queue(new MetaNotResponding($days, $connection->catalogName));
            }
        } catch (Throwable $exception) {
            Cache::forget($marker);
            Log::warning('Meta not responding mail could not be queued: '.$exception->getMessage(), $context);

            return false;
        }

        return true;
    }

    /** @return list<string> dates (Y-m-d) of the current streak */
    private function current(MetaConnection $connection): array
    {
        $days = Cache::get(self::DAYS_KEY.$this->id($connection));
        $days = is_array($days) ? array_values(array_filter($days, 'is_string')) : [];
        $last = end($days);
        if ($last === false || ($last !== now()->toDateString() && $last !== now()->subDay()->toDateString())) {
            return [];
        }

        return $days;
    }

    private function id(MetaConnection $connection): string
    {
        return sha1($connection->businessId.'|'.$connection->connectedAt?->format(DATE_ATOM));
    }
}
