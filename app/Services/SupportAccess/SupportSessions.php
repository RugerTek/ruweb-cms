<?php

namespace App\Services\SupportAccess;

use App\Models\SupportSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The support account of each operator and the life of a support session (docs/operations/support-access.md).
 * The Laravel session carries only SESSION_KEY (the support_sessions id); the row decides whether it is still valid.
 */
class SupportSessions
{
    public const SESSION_KEY = 'support_session_id';

    /** Name prefix of every support account, so the history reads «Soporte RügerTek – Ana». */
    public const NAME_PREFIX = 'Soporte RügerTek – ';

    /**
     * The operator's support account, created on the first entry. An ordinary user with the same email is never
     * reused or converted.
     *
     * @throws SupportOperatorConflict
     */
    public function resolveOperator(SupportPass $pass): User
    {
        return DB::transaction(function () use ($pass): User {
            $user = User::query()->where('email', $pass->email)->lockForUpdate()->first();
            if ($user !== null && ! $user->is_support) {
                throw new SupportOperatorConflict;
            }
            $user ??= new User;
            if (! $user->exists) {
                $user->forceFill([
                    'email' => $pass->email,
                    // Random and never shown: there is no way to know it, and /login refuses support accounts anyway.
                    'password' => Str::random(64),
                    'email_verified_at' => Carbon::now(),
                    'is_support' => true,
                ]);
            }
            $user->forceFill([
                'name' => mb_substr(self::NAME_PREFIX.$pass->name, 0, 255),
                'role' => 'admin',
                'can_manage_custom_code' => $pass->role === 'desarrollo',
            ])->save();

            return $user;
        });
    }

    public function start(User $user, SupportPass $pass, Request $request): SupportSession
    {
        $now = Carbon::now();

        return SupportSession::create([
            'user_id' => $user->id,
            'operator_email' => $pass->email,
            'operator_name' => $pass->name,
            'role' => $pass->role,
            'issuer' => $pass->issuer,
            'jti' => $pass->jti,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'started_at' => $now,
            'expires_at' => $now->copy()->addSeconds($pass->sessionTtl),
        ]);
    }

    /**
     * The live support session of this user and Laravel session, or null. A session found past its expiry is closed
     * here with end_reason «expired».
     */
    public function active(User $user, mixed $sessionId): ?SupportSession
    {
        if (! is_int($sessionId) && ! (is_string($sessionId) && ctype_digit($sessionId))) {
            return null;
        }
        $support = SupportSession::query()->whereKey((int) $sessionId)->where('user_id', $user->id)->first();
        if ($support === null || $support->ended_at !== null) {
            return null;
        }
        if (! $support->isActive()) {
            $this->end($support, 'expired', $support->expires_at);

            return null;
        }

        return $support;
    }

    public function end(SupportSession $support, string $reason, ?Carbon $at = null): void
    {
        SupportSession::query()->whereKey($support->id)->whereNull('ended_at')
            ->update(['ended_at' => $at ?? Carbon::now(), 'end_reason' => $reason]);
        $support->refresh();
    }

    /** Closes the support session bound to this request's Laravel session, if any (logout, exit, new pass). */
    public function endCurrent(Request $request, string $reason): void
    {
        if (! $request->hasSession()) {
            return;
        }
        $id = $request->session()->get(self::SESSION_KEY);
        $user = $request->user();
        if ($id === null || $user === null) {
            return;
        }
        $support = SupportSession::query()->whereKey((int) $id)->where('user_id', $user->id)->whereNull('ended_at')->first();
        if ($support !== null) {
            $this->end($support, $reason);
        }
    }
}
