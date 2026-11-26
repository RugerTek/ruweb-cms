<?php

namespace App\Services\SupportAccess;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Verifies a support pass from the RuWeb Cloud console (docs/operations/support-access.md#pass-format):
 * `<payload>.<signature>`, both base64url without padding, where the signature is ES256 (ECDSA P-256 over SHA-256, as
 * Azure Key Vault `sign` returns it: r||s, 64 bytes) over SIGNING_CONTEXT followed by the payload segment exactly as
 * received. Anything unexpected is a rejection: unknown claims, wrong types, out-of-range values. Comparisons of the
 * audience and issuer are constant-time.
 */
class SupportPassVerifier
{
    public const VERSION = 2;

    /** Domain separation: a console signature over anything else can never be replayed as a pass. */
    public const SIGNING_CONTEXT = "ruweb-support-pass.v2\n";

    public const MAX_LENGTH = 4096;

    public const ROLES = ['operacion', 'desarrollo'];

    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    public const TICKET_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9#._:\/-]{0,63}$/D';

    private const REQUIRED = ['v', 'aud', 'iss', 'sub', 'name', 'role', 'reason', 'iat', 'exp', 'jti'];

    private const OPTIONAL = ['session_ttl', 'ticket'];

    public function __construct(private readonly SupportAccessKeys $keys) {}

    public function enabled(): bool
    {
        return $this->keys->enabled();
    }

    /** @throws InvalidSupportPass */
    public function verify(mixed $token, ?int $now = null): SupportPass
    {
        $now ??= Carbon::now()->getTimestamp();
        $publicKeys = $this->keys->publicKeys();
        $audience = $this->keys->audience();
        if ($publicKeys === [] || $audience === null) {
            throw new InvalidSupportPass('disabled');
        }
        if (! is_string($token) || strlen($token) > self::MAX_LENGTH
            || preg_match('/^[A-Za-z0-9_-]{2,}\.[A-Za-z0-9_-]{86}$/D', $token) !== 1) {
            throw new InvalidSupportPass('malformed');
        }
        [$payloadSegment, $signatureSegment] = explode('.', $token, 2);
        $raw = SupportAccessKeys::base64UrlDecode($signatureSegment);
        $der = $raw === null ? null : Es256Signature::rawToDer($raw);
        if ($der === null) {
            throw new InvalidSupportPass('malformed');
        }

        // Every configured key is tried (no early exit), so the timing does not tell which key matched.
        $valid = false;
        foreach ($publicKeys as $publicKey) {
            if (openssl_verify(self::SIGNING_CONTEXT.$payloadSegment, $der, $publicKey, OPENSSL_ALGO_SHA256) === 1) {
                $valid = true;
            }
        }
        while (openssl_error_string() !== false) {
            // A bad signature leaves OpenSSL errors queued; drop them so they never surface elsewhere.
        }
        if (! $valid) {
            throw new InvalidSupportPass('signature');
        }

        return $this->claims($payloadSegment, $audience, $now);
    }

    /**
     * Marks the pass as used until it can no longer be valid. A second use (replay, double click, race) throws.
     *
     * @throws InvalidSupportPass
     */
    public function consume(SupportPass $pass): void
    {
        $skew = (int) config('cms.support_access.clock_skew', 30);
        try {
            // Own transaction (a savepoint when nested), so a duplicate never aborts an outer one.
            DB::transaction(fn () => DB::table('support_pass_jtis')->insert([
                'jti' => $pass->jti,
                'expires_at' => Carbon::createFromTimestamp($pass->expiresAt + $skew),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new InvalidSupportPass('replay');
        }
        if (DB::table('support_sessions')->where('jti', $pass->jti)->exists()) {
            throw new InvalidSupportPass('replay');
        }
    }

    /**
     * The reason as stored: whitespace runs (line breaks included) collapsed to one space; null when it is not 10–500
     * characters of plain text (no HTML, no control or invisible formatting characters).
     */
    public static function normalizeReason(mixed $reason): ?string
    {
        if (! is_string($reason) || ! mb_check_encoding($reason, 'UTF-8')) {
            return null;
        }
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        $length = mb_strlen($reason);
        if ($length < self::REASON_MIN || $length > self::REASON_MAX
            || preg_match('/[<>]|\p{C}/u', $reason) === 1) {
            return null;
        }

        return $reason;
    }

    /** @throws InvalidSupportPass */
    private function claims(string $payloadSegment, string $audience, int $now): SupportPass
    {
        $json = SupportAccessKeys::base64UrlDecode($payloadSegment);
        try {
            $claims = $json === null ? null : json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $claims = null;
        }
        if (! is_array($claims) || array_is_list($claims)) {
            throw new InvalidSupportPass('payload');
        }
        $keys = array_keys($claims);
        if (array_diff(self::REQUIRED, $keys) !== [] || array_diff($keys, [...self::REQUIRED, ...self::OPTIONAL]) !== []) {
            throw new InvalidSupportPass('claims');
        }
        if ($claims['v'] !== self::VERSION) {
            throw new InvalidSupportPass('version');
        }
        if (! is_string($claims['aud']) || ! hash_equals($audience, strtolower($claims['aud']))) {
            throw new InvalidSupportPass('audience');
        }
        if (! is_string($claims['iss']) || ! hash_equals($this->keys->issuer(), $claims['iss'])) {
            throw new InvalidSupportPass('issuer');
        }

        $iat = $claims['iat'];
        $exp = $claims['exp'];
        $skew = (int) config('cms.support_access.clock_skew', 30);
        if (! is_int($iat) || ! is_int($exp) || $exp <= $iat
            || $exp - $iat > (int) config('cms.support_access.max_pass_lifetime', 30)) {
            throw new InvalidSupportPass('lifetime');
        }
        if ($iat > $now + $skew || $exp < $now - $skew) {
            throw new InvalidSupportPass('expired');
        }

        if (! is_string($claims['jti']) || preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $claims['jti']) !== 1) {
            throw new InvalidSupportPass('jti');
        }

        $email = is_string($claims['sub']) ? strtolower(trim($claims['sub'])) : '';
        $domain = $this->keys->operatorDomain();
        if ($email === '' || strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || $domain === '' || substr($email, strrpos($email, '@') + 1) !== $domain) {
            throw new InvalidSupportPass('operator');
        }

        $name = is_string($claims['name']) ? trim($claims['name']) : '';
        if ($name === '' || ! mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 80 || preg_match('/\p{C}|[<>]/u', $name) === 1) {
            throw new InvalidSupportPass('name');
        }

        if (! in_array($claims['role'], self::ROLES, true)) {
            throw new InvalidSupportPass('role');
        }

        $reason = self::normalizeReason($claims['reason']);
        if ($reason === null) {
            throw new InvalidSupportPass('reason');
        }

        $ticket = $claims['ticket'] ?? null;
        if ($ticket !== null && (! is_string($ticket) || preg_match(self::TICKET_PATTERN, $ticket) !== 1)) {
            throw new InvalidSupportPass('ticket');
        }

        $ttl = $claims['session_ttl'] ?? (int) config('cms.support_access.default_session_ttl', 7200);
        if (! is_int($ttl) || $ttl < 60 || $ttl > (int) config('cms.support_access.max_session_ttl', 14400)) {
            throw new InvalidSupportPass('session_ttl');
        }

        return new SupportPass($audience, $claims['iss'], $email, $name, $claims['role'], $iat, $exp, $claims['jti'], $ttl, $reason, $ticket);
    }
}
