<?php

namespace App\Services\Forms;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google reCAPTCHA v3 verification, driven by the `security` settings group.
 *
 * When no site key is configured the check is disabled, so local
 * environments and tests work without credentials. See docs/content/forms.md.
 */
class Recaptcha
{
    public const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    protected ?float $lastScore = null;

    public function enabled(): bool
    {
        return $this->siteKey() !== '' && trim((string) Setting::get('recaptcha_secret_key')) !== '';
    }

    public function siteKey(): string
    {
        return trim((string) Setting::get('recaptcha_site_key'));
    }

    public function minScore(): float
    {
        $value = Setting::get('recaptcha_min_score');
        $score = is_numeric($value) ? (float) $value : 0.5;

        return max(0.0, min(1.0, $score));
    }

    /**
     * Verify a token. Returns true when the check passes or is disabled.
     * Network failures count as a failed check; the honeypot and rate limit
     * still protect the form, and the visitor gets a clear retry message.
     */
    public function verify(?string $token, ?string $ip, string $expectedAction, ?string $expectedHostname = null): bool
    {
        $this->lastScore = null;

        if (! $this->enabled()) {
            return true;
        }

        if (! is_string($token) || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, [
                'secret' => (string) Setting::get('recaptcha_secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ])->json();
        } catch (\Throwable $exception) {
            Log::warning('reCAPTCHA verification failed: '.$exception->getMessage());

            return false;
        }

        $hostname = $expectedHostname ?? parse_url((string) config('app.url'), PHP_URL_HOST);
        $score = $response['score'] ?? null;
        if (! is_array($response) || ($response['success'] ?? null) !== true
            || ($response['action'] ?? null) !== $expectedAction
            || ! is_string($response['hostname'] ?? null)
            || ! is_string($hostname) || strcasecmp($response['hostname'], $hostname) !== 0
            || (! is_int($score) && ! is_float($score)) || ! is_finite((float) $score)
            || $score < 0 || $score > 1 || $score < $this->minScore()) {
            return false;
        }
        $this->lastScore = (float) $score;

        return true;
    }

    public function lastScore(): ?float
    {
        return $this->lastScore;
    }
}
