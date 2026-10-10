<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $sessionId = hash('sha256', $request->session()->getId());
        $session = DB::table('admin_sessions')->where('id', $sessionId)->first();
        $version = $request->session()->get('security_version');
        if ($session?->revoked_at !== null || ($version === null && $user->security_version > 0)
            || ($version !== null && (int) $version !== (int) $user->security_version)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login');
        }
        $request->session()->put('security_version', (int) $user->security_version);
        if ($user->hasTwoFactor() && $request->session()->get('two_factor_passed') !== $user->id) {
            $request->session()->put(['two_factor_user' => $user->id, 'two_factor_expires' => now()->addMinutes(5)->timestamp, 'two_factor_version' => $user->security_version]);
            Auth::logout();

            return redirect()->route('admin.two-factor.challenge');
        }
        DB::table('admin_sessions')->updateOrInsert(['id' => $sessionId], [
            'user_id' => $user->id, 'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000), 'last_active_at' => now(),
        ]);

        return $next($request);
    }
}
