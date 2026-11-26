<?php

namespace App\Services\SupportAccess;

use App\Models\SupportSession;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The notice on the admin home that tells the client's administrators that RügerTek support entered: who, when and
 * why (docs/operations/support-access.md#client-notice). Shown for DAYS days after each entry or until that
 * administrator dismisses it; never to editors nor to the support accounts themselves.
 */
class SupportNotices
{
    public const DAYS = 7;

    public function visibleTo(?User $user): bool
    {
        return $user !== null && $user->isAdmin() && ! $user->isSupport();
    }

    /**
     * Support sessions to announce to this user, newest first.
     *
     * @return Collection<int, SupportSession>
     */
    public function for(?User $user): Collection
    {
        if (! $this->visibleTo($user)) {
            return collect();
        }
        $since = Carbon::now()->subDays(self::DAYS);
        $dismissed = DB::table('support_notice_dismissals')->where('user_id', $user->id)->value('dismissed_at');
        if ($dismissed !== null && Carbon::parse($dismissed)->isAfter($since)) {
            $since = Carbon::parse($dismissed);
        }

        return SupportSession::query()->where('started_at', '>', $since)
            ->orderByDesc('started_at')->orderByDesc('id')->limit(20)->get();
    }

    public function dismiss(User $user): void
    {
        DB::table('support_notice_dismissals')->upsert(
            [['user_id' => $user->id, 'dismissed_at' => Carbon::now()]],
            ['user_id'],
            ['dismissed_at'],
        );
    }

    /** «hoy a las 15:20», «ayer a las 09:05», «el 24/11 a las 18:00», in the site's time zone. */
    public function when(Carbon $at): string
    {
        $local = $at->copy()->timezone(config('app.timezone'));
        $today = Carbon::now(config('app.timezone'))->startOfDay();
        $time = $local->format('H:i');
        if ($local->isSameDay($today)) {
            return __('support.notice.today', ['time' => $time]);
        }
        if ($local->isSameDay($today->copy()->subDay())) {
            return __('support.notice.yesterday', ['time' => $time]);
        }

        return __('support.notice.on_date', ['date' => $local->format('d/m'), 'time' => $time]);
    }
}
