<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Username;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.profile.index', [
            'user' => $request->user(),
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
        $user->email = $validated['email'];
        if (array_key_exists('username', $validated)) {
            $user->username = $validated['username'];
        }
        $user->save();

        return redirect()
            ->route('admin.profile.index', ['tab' => 'information'])
            ->with('success', __('admin.profile_information_updated'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->password = $validated['password'];
        $user->save();

        return redirect()
            ->route('admin.profile.index', ['tab' => 'security'])
            ->with('success', __('admin.profile_password_updated'));
    }
}
