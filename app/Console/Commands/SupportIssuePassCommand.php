<?php

namespace App\Console\Commands;

use App\Services\SupportAccess\SupportAccessKeys;
use App\Services\SupportAccess\SupportPassIssuer;
use App\Services\SupportAccess\SupportPassVerifier;
use Illuminate\Console\Command;

/**
 * Issues a support pass as the RuWeb Cloud console would (ES256) and prints the entry URL
 * (docs/operations/support-access.md#try-it-locally). Development tool: it refuses unless APP_ENV is local or
 * testing AND RUWEB_SUPPORT_DEV_PRIVATE_KEY holds a P-256 key.
 */
class SupportIssuePassCommand extends Command
{
    protected $signature = 'support:issue-pass
        {email : Operator email (must be of the operator domain, e.g. ana@rugertek.com)}
        {--reason= : Why the operator enters, 10 to 500 characters of plain text (required; the client sees it)}
        {--ticket= : Optional short reference of the request (e.g. TK-1234)}
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
        $privateKey = $keys->devPrivateKey();
        if ($privateKey === null) {
            $this->error('RUWEB_SUPPORT_DEV_PRIVATE_KEY is missing or is not a P-256 key. Generate a pair with php artisan support:keypair.');

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
        $reason = SupportPassVerifier::normalizeReason((string) $this->option('reason'));
        if ($reason === null) {
            $this->error('--reason is required: '.SupportPassVerifier::REASON_MIN.' to '.SupportPassVerifier::REASON_MAX.' characters of plain text, without HTML.');

            return self::FAILURE;
        }
        $ticket = $this->option('ticket');
        if ($ticket !== null && preg_match(SupportPassVerifier::TICKET_PATTERN, (string) $ticket) !== 1) {
            $this->error('--ticket must be a short reference: up to 64 letters, digits and # . _ : / -');

            return self::FAILURE;
        }
        $ttl = $this->option('ttl');
        if ($ttl !== null && (! ctype_digit((string) $ttl) || (int) $ttl < 60 || (int) $ttl > (int) config('cms.support_access.max_session_ttl', 14400))) {
            $this->error('--ttl must be a number of seconds between 60 and '.(int) config('cms.support_access.max_session_ttl', 14400).'.');

            return self::FAILURE;
        }
        $name = trim((string) ($this->option('name') ?: strstr($email, '@', true) ?: $email));

        $publicKey = SupportAccessKeys::parsePublicKey(SupportAccessKeys::publicPemBody($privateKey));
        $trusted = array_map(fn ($key) => openssl_pkey_get_details($key)['key'], $keys->publicKeys());
        if ($publicKey === null || ! in_array(openssl_pkey_get_details($publicKey)['key'], $trusted, true)) {
            $this->warn('The public key of RUWEB_SUPPORT_DEV_PRIVATE_KEY is not in RUWEB_SUPPORT_PUBLIC_KEYS: this site will reject the pass.');
        }

        $pass = SupportPassIssuer::issue($privateKey, $audience, $keys->issuer(), $email, $name, $reason, $role, $ttl === null ? null : (int) $ttl, $ticket === null ? null : (string) $ticket);

        $this->line('Single-use entry URL, valid for '.SupportPassIssuer::LIFETIME.' seconds:');
        $this->line(rtrim((string) config('app.url'), '/').'/_ruweb/support/enter?t='.$pass);

        return self::SUCCESS;
    }
}
