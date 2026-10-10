<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\RevokeCredentials;
use App\Services\Security\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function challenge(Request $request)
    {
        abort_unless($request->session()->has('two_factor_user'), 403);

        return view('admin.auth.two-factor');
    }

    public function verifyChallenge(Request $request, Totp $totp)
    {
        $request->validate(['code' => ['required', 'string', 'max:100']]);
        $user = User::findOrFail($request->session()->get('two_factor_user'));
        if (! $user->hasTwoFactor() || $request->session()->get('two_factor_expires', 0) < now()->timestamp
            || (int) $request->session()->get('two_factor_version') !== (int) $user->security_version
            || ! $totp->verify($user, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('security.invalid_code')]);
        }
        Auth::login($user, $request->session()->pull('two_factor_remember', false));
        $request->session()->forget(['two_factor_user', 'two_factor_expires', 'two_factor_version']);
        $request->session()->regenerate();
        $request->session()->put(['two_factor_passed' => $user->id, 'security_version' => $user->security_version]);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function enable(Request $request, Totp $totp)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        abort_if($request->user()->hasTwoFactor(), 409);
        $request->user()->forceFill(['two_factor_secret' => $totp->secret(), 'two_factor_last_counter' => null])->save();

        return back()->with('success', __('security.enrollment_started'));
    }

    public function confirm(Request $request, Totp $totp, RevokeCredentials $revoke)
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);
        abort_if($request->user()->hasTwoFactor(), 409);
        if (! $totp->verify($request->user(), $request->input('code'), false)) {
            throw ValidationException::withMessages(['code' => __('security.invalid_code')]);
        }
        $user = $request->user();
        $user->forceFill(['two_factor_confirmed_at' => now()]);
        $revoke->all($user, $request->session()->getId());
        $request->session()->put(['two_factor_passed' => $user->id, 'security_version' => $user->fresh()->security_version]);

        return back()->with('recovery_codes', $totp->recoveryCodes($user))->with('success', __('security.enabled'));
    }

    public function disable(Request $request, Totp $totp, RevokeCredentials $revoke)
    {
        $request->validate(['current_password' => ['required', 'current_password'], 'code' => ['required', 'string']]);
        if (! $totp->verify($request->user(), $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('security.invalid_code')]);
        }
        $user = $request->user();
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null, 'two_factor_last_counter' => null]);
        $revoke->all($user, $request->session()->getId());
        $request->session()->put('security_version', $user->fresh()->security_version);

        return back()->with('success', __('security.disabled'));
    }

    public function recovery(Request $request, Totp $totp)
    {
        $request->validate(['current_password' => ['required', 'current_password'], 'code' => ['required', 'string']]);
        abort_unless($request->user()->hasTwoFactor(), 403);
        if (! $totp->verify($request->user(), $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('security.invalid_code')]);
        }

        return back()->with('recovery_codes', $totp->recoveryCodes($request->user()));
    }
}
