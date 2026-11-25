<?php

namespace App\Console\Commands;

use App\Services\SupportAccess\SupportAccessKeys;
use App\Services\SupportAccess\SupportPassIssuer;
use App\Services\SupportAccess\SupportPassVerifier;
use Illuminate\Console\Command;

/**
 * Issues a support pass as the RuWeb Cloud console would and prints the entry URL
 * (docs/operations/support-access.md#try-it-locally). Development tool: it refuses unless APP_ENV is local or
 * testing AND RUWEB_SUPPORT_DEV_PRIVATE_KEY holds a key.
 */
class SupportIssuePassCommand extends Command
{
    protected $signature = 'support:issue-pass
        {email : Operator email (must be of the operator domain, e.g. ana@rugertek.com)}
        {--name= : Operator name shown in the bar and the record (default: the part of the email before @)}
        {--ttl= : Support session length in seconds (60 to 14400; default 7200)}
        {--role=operacion : operacion or desarrollo}';

    protected $description = 'Issue a development RügerTek support pass and print the entry URL (local/testing only)';

    public function handle(SupportAccessKeys $keys): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('support:issue-pass only runs with APP_ENV=local or testing. Real passes are issued by the RuWeb Cloud console.');

            return self::FAILURE;
        }
        $secretKey = $keys->devSecretKey();
        if ($secretKey === null) {
            $this->error('RUWEB_SUPPORT_DEV_PRIVATE_KEY is missing or invalid. Generate a pair with php artisan support:keypair.');

            return self::FAILURE;
        }
        $audience = $keys->audience();
        if ($audience === null) {
            $this->error('APP_URL must be an absolute http(s) URL: it is the audience of the pass.');

            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->argument('email')));
        $role = (string) $this->option('role');
        if (! in_array($role, SupportPassVerifier::ROLES, true)) {
            $this->error('--role must be operacion or desarrollo.');

            return self::FAILURE;
        }
        $ttl = $this->option('ttl');
        if ($ttl !== null && (! ctype_digit((string) $ttl) || (int) $ttl < 60 || (int) $ttl > (int) config('cms.support_access.max_session_ttl', 14400))) {
            $this->error('--ttl must be a number of seconds between 60 and '.(int) config('cms.support_access.max_session_ttl', 14400).'.');

            return self::FAILURE;
        }
        $name = trim((string) ($this->option('name') ?: strstr($email, '@', true) ?: $email));

        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
        if (! in_array($publicKey, $keys->publicKeys(), true)) {
            $this->warn('The public key of RUWEB_SUPPORT_DEV_PRIVATE_KEY is not in RUWEB_SUPPORT_PUBLIC_KEYS: this site will reject the pass.');
        }

        $pass = SupportPassIssuer::issue($secretKey, $audience, $keys->issuer(), $email, $name, $role, $ttl === null ? null : (int) $ttl);
        sodium_memzero($secretKey);

        $this->line('Single-use entry URL, valid for 60 seconds:');
        $this->line(rtrim((string) config('app.url'), '/').'/_ruweb/support/enter?t='.$pass);

        return self::SUCCESS;
    }
}
