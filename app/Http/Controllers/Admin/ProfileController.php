<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OauthAccessToken;
use App\Models\User;
use App\Notifications\VerifyPendingEmail;
use App\Services\Security\RevokeCredentials;
use App\Support\Username;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.profile.index', [
            'user' => $request->user(),
            'sessions' => DB::table('admin_sessions')->where('user_id', $request->user()->id)
                ->whereNull('revoked_at')->where('last_active_at', '>', now()->subMinutes(config('session.lifetime', 120)))->latest('last_active_at')->get(),
            'currentSession' => hash('sha256', $request->session()->getId()),
            'connections' => OauthAccessToken::where('user_id', $request->user()->id)
                ->whereNull('revoked_at')->where('expires_at', '>', now())->get(),
        ]);
    }

    public function updateInformation(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($request->has('username')) {
            $request->merge(['username' => Username::normalize($request->input('username'))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => Username::rules($user->id),
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->name = $validated['name'];
        if ($validated['email'] !== $user->email) {
            $request->validate(['current_password' => ['required', 'current_password'],
                'email_confirmation' => ['required', 'same:email']]);
            $token = bin2hex(random_bytes(32));
            $user->forceFill(['pending_email' => $validated['email'],
                'pending_email_token' => hash('sha256', $token), 'pending_email_expires_at' => now()->addHour()]);
        }
        if (array_key_exists('username', $validated)) {
            $user->username = $validated['username'];
        }
        $user->save();
        if (isset($token)) {
            Notification::route('mail', $user->pending_email)
                ->notify(new VerifyPendingEmail($token, $user));
        }

        return redirect()
            ->route('admin.profile.index', ['tab' => 'information'])
            ->with('success', __('admin.profile_information_updated'));
    }

    public function verifyEmail(Request $request, User $user, string $token): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        DB::transaction(function () use ($user, $token) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->pending_email && $user->pending_email_expires_at?->isFuture()
                && hash_equals($user->pending_email_token ?? '', hash('sha256', $token)), 403);
            abort_if(User::where('email', $user->pending_email)->whereKeyNot($user->id)->exists(), 409);
            $user->forceFill(['email' => $user->pending_email, 'email_verified_at' => now(),
                'pending_email' => null, 'pending_email_token' => null, 'pending_email_expires_at' => null])->save();
            app(RevokeCredentials::class)->all($user);
        });

        if ($request->user()?->id === $user->id) {
            \Illuminate\Support\Facades\Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
        return redirect()->route('admin.login')->with('success', __('security.email_verified'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->password = $validated['password'];
        app(RevokeCredentials::class)->all($user, $request->session()->getId());
        \Illuminate\Support\Facades\DB::table('admin_sessions')->where('id', hash('sha256', $request->session()->getId()))->update(['revoked_at' => now()]);
        $request->session()->regenerate();
        $request->session()->put('security_version', $user->fresh()->security_version);

        return redirect()
            ->route('admin.profile.index', ['tab' => 'security'])
            ->with('success', __('admin.profile_password_updated'));
    }
}
