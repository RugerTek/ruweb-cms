<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use App\Models\Setting;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class BrevoMailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('brevo', function (array $config = []) {
            $apiKey = $this->resolveApiKey($config);

            return new BrevoTransport($apiKey);
        });

        $this->applyRuntimeMailConfig();
    }

    /**
     * Pulls Brevo key and from-address from DB settings so the admin UI can
     * drive email config without touching .env. Falls back to env values.
     */
    protected function applyRuntimeMailConfig(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $enabled = Setting::get('mail_enabled');
            if ($enabled && filled($this->resolveApiKey(config('mail.mailers.brevo', [])))) {
                config(['mail.default' => 'brevo']);
            }

            $fromAddress = Setting::get('mail_from_address');
            $fromName = Setting::get('mail_from_name');
            if ($fromAddress) {
                config(['mail.from.address' => $fromAddress]);
            }
            if ($fromName) {
                config(['mail.from.name' => $fromName]);
            }
        } catch (Throwable) {
            // Settings table not yet migrated; silently keep env defaults.
        }
    }

    protected function resolveApiKey(array $config): string
    {
        try {
            if (Schema::hasTable('settings')) {
                $key = Setting::get('brevo_api_key');
                if (filled($key)) {
                    return $key;
                }
            }
        } catch (Throwable) {
            // fall through
        }

        return (string) ($config['key'] ?? env('BREVO_API_KEY', ''));
    }
}
