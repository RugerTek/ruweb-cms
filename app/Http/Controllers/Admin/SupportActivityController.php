<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportSession;
use App\Services\SupportAccess\SupportAccessSwitch;
use App\Services\SupportAccess\SupportActivitySummary;
use App\Services\SupportAccess\SupportNotices;
use App\Services\SupportAccess\SupportSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin > Actividad de soporte (administrators, the client's included): every RügerTek support session, who, when, why,
 * how long and what changed (docs/operations/support-access.md#activity-screen). Also the switch «Permitir acceso de
 * soporte de RügerTek» (client administrators only, never a support account), the dismissal of the home notice and
 * «Salir del modo soporte».
 */
class SupportActivityController extends Controller
{
    /** Changes listed per session on the index; the detail shows all of them. */
    public const CHANGES_PREVIEW = 5;

    public const DETAIL_LIMIT = 1000;

    public function index(Request $request, SupportActivitySummary $summary, SupportAccessSwitch $switch): View
    {
        $sessions = SupportSession::query()
            ->withCount([
                'audits as changes_count' => fn ($query) => $query->whereNotIn('method', ['GET', 'HEAD']),
                'audits as views_count' => fn ($query) => $query->whereIn('method', ['GET', 'HEAD']),
            ])
            ->orderByDesc('started_at')->orderByDesc('id')
            ->paginate(20);
        $sessions->load(['audits' => fn ($query) => $query->whereNotIn('method', ['GET', 'HEAD'])->orderBy('at')->orderBy('id')]);

        return view('admin.support.index', [
            'sessions' => $sessions,
            'summary' => $summary,
            'retentionDays' => (int) config('cms.support_access.retention_days', 365),
            'access' => $switch->state(),
            'canChangeAccess' => $switch->canChange($request->user()),
        ]);
    }

    public function show(int $supportSession, SupportActivitySummary $summary): View
    {
        $session = SupportSession::query()->findOrFail($supportSession);

        return view('admin.support.show', [
            'session' => $session,
            'entries' => $session->audits()->orderBy('at')->orderBy('id')->limit(self::DETAIL_LIMIT)->get(),
            'summary' => $summary,
        ]);
    }

    /** «Permitir acceso de soporte de RügerTek»: on or off, confirmed, recorded with who and when. */
    public function access(Request $request, SupportAccessSwitch $switch): RedirectResponse
    {
        abort_unless($switch->canChange($request->user()), 403, __('support.access.only_client_admins'));
        $validated = $request->validate([
            'allowed' => ['required', 'boolean'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => __('support.access.confirm_required')]);

        $allowed = (bool) $validated['allowed'];
        $closed = $switch->set($allowed, $request->user(), $request);

        return redirect()->route('admin.support-activity.index')->with('success', $allowed
            ? __('support.access.turned_on')
            : trans_choice('support.access.turned_off', $closed, ['count' => $closed]));
    }

    /** Hides the home notice of the support entries so far, for this administrator only. */
    public function dismissNotice(Request $request, SupportNotices $notices): RedirectResponse
    {
        abort_unless($notices->visibleTo($request->user()), 403);
        $notices->dismiss($request->user());

        return redirect()->back(fallback: route('admin.dashboard'));
    }

    /** «Salir del modo soporte»: closes the support session at once and signs the operator out. */
    public function exit(Request $request, SupportSessions $sessions): RedirectResponse
    {
        $support = $request->attributes->get('support_session');
        abort_unless($support instanceof SupportSession && $request->user()?->isSupport(), 404);

        $sessions->end($support, 'exit');
        DB::table('admin_sessions')->where('id', hash('sha256', $request->session()->getId()))->update(['revoked_at' => now()]);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
