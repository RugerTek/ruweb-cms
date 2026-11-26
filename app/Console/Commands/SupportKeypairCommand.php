<?php

namespace App\Console\Commands;

use App\Services\SupportAccess\SupportAccessKeys;
use App\Services\SupportAccess\SupportPassIssuer;
use Illuminate\Console\Command;

/**
 * Generates an ES256 (ECDSA P-256) key pair for trying support access on a development site
 * (docs/operations/support-access.md#try-it-locally). Refuses outside local/testing: a real site only ever holds the
 * console's public key, and the production key is created in Azure Key Vault, not exportable.
 */
class SupportKeypairCommand extends Command
{
    protected $signature = 'support:keypair';

    protected $description = 'Generate a development ES256 (P-256) key pair for RügerTek support passes (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('support:keypair only runs with APP_ENV=local or testing. Production sites hold only the console public key.');

            return self::FAILURE;
        }

        $key = SupportPassIssuer::generateKey();
        $this->line('Development key pair (never use it on a real site):');
        $this->newLine();
        // Single quotes: the JWK has double quotes and commas of its own.
        $this->line("RUWEB_SUPPORT_PUBLIC_KEYS='".SupportAccessKeys::publicJwk($key)."'");
        $this->line('RUWEB_SUPPORT_DEV_PRIVATE_KEY='.SupportPassIssuer::privateKeyBody($key));
        $this->newLine();
        $this->line('The same public key as a PEM body, also accepted in RUWEB_SUPPORT_PUBLIC_KEYS:');
        $this->line(SupportAccessKeys::publicPemBody($key));
        $this->newLine();
        $this->line('Add the first two lines to .env, run php artisan config:clear if config is cached, then php artisan support:issue-pass <email> --reason="…".');

        return self::SUCCESS;
    }
}
