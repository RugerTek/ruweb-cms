<?php

namespace App\Http\Controllers;

use App\Services\SupportAccess\InvalidSupportPass;
use App\Services\SupportAccess\SupportAccessSwitch;
use App\Services\SupportAccess\SupportOperatorConflict;
use App\Services\SupportAccess\SupportPassVerifier;
use App\Services\SupportAccess\SupportSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /_ruweb/support/enter?t=<pass>: a RügerTek operator enters the admin with a pass signed by the RuWeb Cloud
 * console, without a password (docs/operations/support-access.md#flow). Every rejection, and a site whose client
 * turned support access off, answers the same plain 404,
 * rendered here instead of thrown so the 404 monitor never stores the URL; the log gets a reason code and the IP,
 * never the pass.
 */
class SupportAccessController extends Controller
{
    public function enter(Request $request, SupportPassVerifier $verifier, SupportSessions $sessions, SupportAccessSwitch $switch): Response
    {
        if (! $verifier->enabled()) {
            return $this->notFound();
        }
        if (! $switch->allowed()) {
            // The client turned support access off: same 404 as any other rejection, the pass is not even read.
            Log::warning('Support pass rejected.', ['reason' => 'switched_off', 'ip' => $request->ip()]);

            return $this->notFound();
        }
        try {
            $pass = $verifier->verify($request->query('t'));
            $verifier->consume($pass);
            $user = $sessions->resolveOperator($pass);
        } catch (InvalidSupportPass $exception) {
            Log::warning('Support pass rejected.', ['reason' => $exception->reason, 'ip' => $request->ip()]);

            return $this->notFound();
        } catch (SupportOperatorConflict) {
            Log::warning('Support pass refused: the operator email belongs to a user of the site.', ['ip' => $request->ip()]);

            return $this->secure(response()->view('support.conflict', ['email' => $pass->email], 409));
        }

        // Whoever was signed in in this browser (another operator, a client user) is signed out first.
        if (Auth::check()) {
            $sessions->endCurrent($request, 'replaced');
            Auth::guard('web')->logout();
        }
        $request->session()->invalidate();
        Auth::guard('web')->login($user);
        $request->session()->regenerateToken();
        $support = $sessions->start($user, $pass, $request);
        $request->session()->put([
            'security_version' => (int) $user->security_version,
            SupportSessions::SESSION_KEY => $support->id,
        ]);
        Log::info('Support session started.', ['support_session' => $support->id, 'operator' => $pass->email, 'ip' => $request->ip()]);

        return $this->secure(redirect()->route('admin.dashboard'));
    }

    private function notFound(): Response
    {
        return $this->secure(response()->view('errors.404', [], 404));
    }

    /** The pass is in this URL: never cache it and never send it as a Referer. */
    private function secure(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
