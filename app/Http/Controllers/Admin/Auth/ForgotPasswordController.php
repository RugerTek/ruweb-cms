<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    public function showLinkRequestForm(): View
    {
        return view('admin.auth.forgot-password');
    }

    public function sendResetLinkEmail(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Support accounts never get a reset link (same answer, so it tells nothing): docs/operations/support-access.md.
        Password::sendResetLink([...$request->only('email'), fn ($query) => $query->where('is_support', false)]);

        return back()->with('success', __('security.reset_sent'));
    }
}
