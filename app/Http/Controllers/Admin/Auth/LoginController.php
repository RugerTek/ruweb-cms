<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function showLoginForm(): View
    {
        return view('admin.auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $user = $request->user();
        if ($user->hasTwoFactor()) {
            Auth::logout();
            $request->session()->regenerate();
            $request->session()->put(['two_factor_user' => $user->id,
                'two_factor_expires' => now()->addMinutes(5)->timestamp,
                'two_factor_version' => $user->security_version,
                'two_factor_remember' => $request->boolean('remember')]);

            return redirect()->route('admin.two-factor.challenge');
        }
        $request->session()->regenerate();
        $request->session()->put('security_version', $user->security_version);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        \Illuminate\Support\Facades\DB::table('admin_sessions')->where('id', hash('sha256', $request->session()->getId()))->update(['revoked_at' => now()]);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
