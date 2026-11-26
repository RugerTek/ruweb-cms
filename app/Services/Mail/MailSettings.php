<?php

namespace App\Services\Mail;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The site's outgoing email, as Admin > Email configures it (docs/operations/email.md). One place answers which
 * provider sends (`mail_provider`: Brevo with an API key, or any SMTP server), whether sending is on and complete,
 * which Laravel mailer to use, and the last delivery error. Every sender of the site (order and connector notices,
 * contact form, test email) asks here, and none of them may block a feature when email is off or fails: they log
 * and go on (Fabrizio's rule, «RuWeb hosteada/autohosteada y correo»).
 *
 * Secrets (the Brevo key and the SMTP password) are `password` settings, stored encrypted, never returned to a form
 * or through MCP; summary() reports only whether they are set. Error messages are scrubbed of both before storing.
 */
final class MailSettings
{
    public const PROVIDER_BREVO = 'brevo';

    public const PROVIDER_SMTP = 'smtp';

    public const PROVIDERS = [self::PROVIDER_BREVO, self::PROVIDER_SMTP];

    /** `tls` = STARTTLS required, `ssl` = implicit TLS (smtps, usually port 465), `none` = plain connection. */
    public const ENCRYPTIONS = ['tls', 'ssl', 'none'];

    /** Seconds an SMTP connection may wait, so a wrong host fails the test email instead of hanging the panel. */
    public const SMTP_TIMEOUT = 15;

    private const LAST_ERROR_KEY = 'mail:last_error';

    private const ERROR_MAX = 500;

    public function provider(): string
    {
        $provider = (string) Setting::get('mail_provider', self::PROVIDER_BREVO);

        return in_array($provider, self::PROVIDERS, true) ? $provider : self::PROVIDER_BREVO;
    }

    /** The «Activar envío de emails» switch. */
    public function enabled(): bool
    {
        return (bool) Setting::get('mail_enabled', false);
    }

    /** The chosen provider has what it needs to connect: the Brevo key, or the SMTP host and port. */
    public function configured(): bool
    {
        return $this->provider() === self::PROVIDER_SMTP
            ? $this->smtpHost() !== '' && $this->smtpPort() > 0
            : $this->brevoKey() !== '';
    }

    /** Email goes out: switched on and complete. When false, customers and administrators get no email at all. */
    public function active(): bool
    {
        return $this->enabled() && $this->configured();
    }

    /**
     * Laravel mailer every site email uses: `smtp` or `brevo` when the chosen provider is complete, otherwise the
     * deployment's default (MAIL_MAILER), so an .env-only setup keeps working.
     */
    public function mailer(): string
    {
        if ($this->provider() === self::PROVIDER_SMTP) {
            return $this->configured() ? self::PROVIDER_SMTP : (string) config('mail.default');
        }

        return $this->brevoKey() !== '' ? self::PROVIDER_BREVO : (string) config('mail.default');
    }

    /** Effective Brevo key: the panel setting, else BREVO_API_KEY (config mail.mailers.brevo.key). */
    public function brevoKey(): string
    {
        $key = trim((string) $this->setting('brevo_api_key'));

        return $key !== '' ? $key : trim((string) config('mail.mailers.brevo.key'));
    }

    /**
     * Point Laravel at the panel settings: the SMTP mailer from `smtp_*` when SMTP is chosen and complete, the default
     * mailer when sending is on, and the sender. Called on boot (App\Providers\BrevoMailServiceProvider) and again
     * before a test email, so a change saved in this request is used. A missing settings table keeps the .env values.
     */
    public function apply(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }
            if ($this->provider() === self::PROVIDER_SMTP && $this->configured()) {
                config(['mail.mailers.smtp' => $this->smtpConfig()]);
                Mail::purge(self::PROVIDER_SMTP);
            }
            if ($this->active()) {
                config(['mail.default' => $this->mailer()]);
            }
            $fromAddress = trim((string) Setting::get('mail_from_address'));
            $fromName = trim((string) Setting::get('mail_from_name'));
            if ($fromAddress !== '') {
                config(['mail.from.address' => $fromAddress]);
            }
            if ($fromName !== '') {
                config(['mail.from.name' => $fromName]);
            }
        } catch (Throwable) {
            // Settings not migrated yet (installer, first deploy): keep the .env values.
        }
    }

    /**
     * Laravel's `smtp` mailer config from the panel. `tls` requires STARTTLS, `ssl` connects with TLS from the start,
     * `none` never upgrades the connection.
     *
     * @return array<string, mixed>
     */
    public function smtpConfig(): array
    {
        $encryption = $this->smtpEncryption();
        $username = trim((string) Setting::get('smtp_username'));
        $password = (string) $this->setting('smtp_password');

        return [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $this->smtpHost(),
            'port' => $this->smtpPort(),
            'username' => $username !== '' ? $username : null,
            'password' => $password !== '' ? $password : null,
            'timeout' => self::SMTP_TIMEOUT,
            'auto_tls' => $encryption === 'none' ? 'false' : 'true',
            'require_tls' => $encryption === 'tls' ? 'true' : 'false',
            'local_domain' => config('mail.mailers.smtp.local_domain'),
        ];
    }

    public function smtpHost(): string
    {
        return trim((string) Setting::get('smtp_host'));
    }

    public function smtpPort(): int
    {
        $port = (int) Setting::get('smtp_port');

        return $port >= 1 && $port <= 65535 ? $port : 0;
    }

    public function smtpEncryption(): string
    {
        $encryption = (string) Setting::get('smtp_encryption', 'tls');

        return in_array($encryption, self::ENCRYPTIONS, true) ? $encryption : 'tls';
    }

    /** Remember a delivery failure (test email, queued email, contact notification) without its secrets. */
    public function recordError(Throwable|string $error): void
    {
        $message = $this->scrub($error instanceof Throwable ? $error->getMessage() : $error);
        try {
            Cache::forever(self::LAST_ERROR_KEY, ['message' => $message, 'at' => now()->toIso8601String(), 'provider' => $this->provider()]);
        } catch (Throwable) {
            // The cache store is down too: the failure is already in the log.
        }
    }

    /** An email went out: the last error no longer describes the current state. */
    public function clearError(): void
    {
        try {
            Cache::forget(self::LAST_ERROR_KEY);
        } catch (Throwable) {
            // Nothing to clear when the cache store is unavailable.
        }
    }

    /** @return array{message: string, at: string, provider: string}|null */
    public function lastError(): ?array
    {
        try {
            $error = Cache::get(self::LAST_ERROR_KEY);
        } catch (Throwable) {
            return null;
        }

        return is_array($error) && isset($error['message'], $error['at']) ? $error + ['provider' => $this->provider()] : null;
    }

    /** Remove the configured secrets from a message (some servers echo credentials back) and shorten it. */
    public function scrub(string $message): string
    {
        foreach ([(string) $this->setting('smtp_password'), $this->brevoKey()] as $secret) {
            if (strlen($secret) >= 4) {
                $message = str_replace($secret, '[oculto]', $message);
            }
        }

        return mb_substr(trim($message), 0, self::ERROR_MAX);
    }

    /**
     * Email state for MCP (cms_capabilities.mail) and the panel: provider, switches and the last error, never a
     * secret (only whether the Brevo key and the SMTP password are set).
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'provider' => $this->provider(),
            'enabled' => $this->enabled(),
            'configured' => $this->configured(),
            'active' => $this->active(),
            'from_address' => trim((string) Setting::get('mail_from_address')) ?: null,
            'brevo' => ['key_configured' => $this->brevoKey() !== ''],
            'smtp' => [
                'host' => $this->smtpHost() ?: null,
                'port' => $this->smtpPort() ?: null,
                'encryption' => $this->smtpEncryption(),
                'username_configured' => trim((string) Setting::get('smtp_username')) !== '',
                'password_configured' => (string) $this->setting('smtp_password') !== '',
            ],
            'last_error' => $this->lastError(),
            'guide' => 'docs/operations/email.md',
        ];
    }

    /** A setting that may not exist yet (sites migrated before it) or whose encrypted value cannot be read. */
    private function setting(string $key): mixed
    {
        try {
            return Setting::get($key);
        } catch (Throwable) {
            return null;
        }
    }
}
