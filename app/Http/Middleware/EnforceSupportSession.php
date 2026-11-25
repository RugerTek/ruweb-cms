<?php

namespace App\Http\Middleware;

use App\Services\SupportAccess\SupportActivityRecorder;
use App\Services\SupportAccess\SupportSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin requests of a RügerTek support account (docs/operations/support-access.md#support-sessions): they need a
 * live support session, which ends at its fixed expiry whatever the activity. The support account cannot create
 * credentials that would outlive the session (password, email, two-factor, MCP keys). For ordinary users it only
 * drops a stray support marker.
 */
class EnforceSupportSession
{
    /** Routes that would give the support account a way in without a pass. */
    private const CREDENTIAL_ROUTES = [
        'admin.profile.information.update',
        'admin.profile.password.update',
        'admin.two-factor.enable',
        'admin.two-factor.confirm',
        'admin.two-factor.disable',
        'admin.two-factor.recovery',
        'admin.claude-mcp.api-key.generate',
    ];

    public function __construct(private readonly SupportSessions $sessions, private readonly SupportActivityRecorder $recorder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $user->isSupport()) {
            if ($request->session()->has(SupportSessions::SESSION_KEY)) {
                $request->session()->forget(SupportSessions::SESSION_KEY);
            }

            return $next($request);
        }

        $sessionId = $request->session()->get(SupportSessions::SESSION_KEY);
        $support = $this->sessions->active($user, $sessionId);
        if ($support === null) {
            $expired = $sessionId !== null;
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $expired
                ? response()->view('support.ended', [], 403)
                : response()->view('errors.404', [], 404);
        }

        $request->attributes->set('support_session', $support);
        View::share('supportSession', $support);

        if (in_array($request->route()?->getName(), self::CREDENTIAL_ROUTES, true)) {
            // Recorded like any other write: the attempt is part of what the client may want to see.
            $this->recorder->record($request, $support, 403);
            abort(403, __('support.credentials_blocked'));
        }

        return $next($request);
    }
}
