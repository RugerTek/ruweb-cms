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
        return $this->siteKey() !== '' && (string) Setting::get('recaptcha_secret_key') !== '';
    }

    public function siteKey(): string
    {
        return trim((string) Setting::get('recaptcha_site_key'));
    }

    public function minScore(): float
    {
        $score = (float) (Setting::get('recaptcha_min_score') ?: 0.5);

        return max(0.0, min(1.0, $score));
    }

    /**
     * Verify a token. Returns true when the check passes or is disabled.
     * Network failures count as a failed check; the honeypot and rate limit
     * still protect the form, and the visitor gets a clear retry message.
     */
    public function verify(?string $token, ?string $ip, string $expectedAction): bool
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

        if (! is_array($response) || ! ($response['success'] ?? false)) {
            return false;
        }

        if (isset($response['action']) && $response['action'] !== $expectedAction) {
            return false;
        }

        $this->lastScore = isset($response['score']) ? (float) $response['score'] : null;

        return $this->lastScore === null || $this->lastScore >= $this->minScore();
    }

    public function lastScore(): ?float
    {
        return $this->lastScore;
    }
}
