<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportSession;
use App\Services\SupportAccess\SupportActivitySummary;
use App\Services\SupportAccess\SupportSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin > Actividad de soporte (administrators, the client's included): every RügerTek support session, who, when,
 * how long and what changed. There is deliberately no switch to turn support access off; this record is the
 * transparency (docs/operations/support-access.md#activity-screen). Also «Salir del modo soporte».
 */
class SupportActivityController extends Controller
{
    /** Changes listed per session on the index; the detail shows all of them. */
    public const CHANGES_PREVIEW = 5;

    public const DETAIL_LIMIT = 1000;

    public function index(SupportActivitySummary $summary): View
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
