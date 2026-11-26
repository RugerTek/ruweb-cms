<?php

namespace App\Services\SupportAccess;

use App\Models\SupportSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * «Permitir acceso de soporte de RügerTek» (docs/operations/support-access.md#turning-it-off): each site decides. On
 * by default (no row yet); every change is a row in `support_access_changes` (who and when), so the current state is
 * the latest row. Only the client's own administrators may change it, never a support account. Turning it off closes
 * every open support session at once; EnforceSupportSession also checks it on every request of a support account.
 */
class SupportAccessSwitch
{
    public const END_REASON = 'disabled';

    /** @var object{allowed: bool|int, user_name: string, changed_at: string}|false|null */
    private object|false|null $latest = false;

    public function allowed(): bool
    {
        $latest = $this->latest();

        return $latest === null || (bool) $latest->allowed;
    }

    /** @return array{allowed: bool, changed_by: ?string, changed_at: ?Carbon} */
    public function state(): array
    {
        $latest = $this->latest();

        return [
            'allowed' => $latest === null || (bool) $latest->allowed,
            'changed_by' => $latest?->user_name,
            'changed_at' => $latest === null ? null : Carbon::parse($latest->changed_at),
        ];
    }

    /** Who may change it: an administrator of the client, never a RügerTek support account. */
    public function canChange(?User $user): bool
    {
        return $user !== null && $user->isAdmin() && ! $user->isSupport();
    }

    /** @return int support sessions closed by this change */
    public function set(bool $allowed, User $by, Request $request): int
    {
        if (! $this->canChange($by)) {
            throw new InvalidArgumentException('Only an administrator of the client can change support access.');
        }
        $closed = DB::transaction(function () use ($allowed, $by, $request): int {
            $now = Carbon::now();
            DB::table('support_access_changes')->insert([
                'allowed' => $allowed,
                'user_id' => $by->id,
                'user_name' => mb_substr((string) $by->name, 0, 255),
                'ip' => $request->ip(),
                'changed_at' => $now,
            ]);

            return $allowed ? 0 : SupportSession::query()->whereNull('ended_at')
                ->update(['ended_at' => $now, 'end_reason' => self::END_REASON]);
        });
        $this->latest = false;
        Log::info($allowed ? 'Support access turned on.' : 'Support access turned off.', ['user' => $by->id, 'closed_sessions' => $closed]);

        return $closed;
    }

    /** @return object{allowed: bool|int, user_name: string, changed_at: string}|null */
    private function latest(): ?object
    {
        if ($this->latest === false) {
            $this->latest = DB::table('support_access_changes')->orderByDesc('changed_at')->orderByDesc('id')->first();
        }

        return $this->latest;
    }
}
