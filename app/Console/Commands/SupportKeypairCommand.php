<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generates an Ed25519 key pair for trying support access on a development site
 * (docs/operations/support-access.md#try-it-locally). Refuses outside local/testing: a real site only ever holds the
 * console's public key, and the console generates its own pair.
 */
class SupportKeypairCommand extends Command
{
    protected $signature = 'support:keypair';

    protected $description = 'Generate a development Ed25519 key pair for RügerTek support passes (local/testing only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('support:keypair only runs with APP_ENV=local or testing. Production sites hold only the console public key.');

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $this->line('Development key pair (never use it on a real site):');
        $this->newLine();
        $this->line('RUWEB_SUPPORT_PUBLIC_KEYS='.base64_encode(sodium_crypto_sign_publickey($pair)));
        $this->line('RUWEB_SUPPORT_DEV_PRIVATE_KEY='.base64_encode(sodium_crypto_sign_secretkey($pair)));
        $this->newLine();
        $this->line('Add both lines to .env, run php artisan config:clear if config is cached, then php artisan support:issue-pass <email>.');
        sodium_memzero($pair);

        return self::SUCCESS;
    }
}
