<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Mail\UserWelcome;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;
use Throwable;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $query = User::query()->latest();

        if ($request->filled('search')) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        return view('admin.users.index', [
            'users' => $query->paginate(15)->withQueryString(),
            'totalCount' => User::count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create', [
            'roles' => config('cms.roles', []),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $plainPassword = $data['password'];

        $user = User::create($data);
        $user->forceFill(['email_verified_at' => now()])->save();

        $mailSent = true;
        try {
            Mail::to($user->email)->send(new UserWelcome($user, $plainPassword));
        } catch (Throwable $e) {
            $mailSent = false;
            Log::warning('Welcome email failed for user '.$user->id.': '.$e->getMessage());
        }

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('success', $mailSent ? __('admin.user_created_with_email') : __('admin.user_created_email_failed'));
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', [
            'user' => $user,
            'roles' => config('cms.roles', []),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if ($request->user()->is($user) && ($data['role'] ?? null) !== 'admin') {
            return redirect()
                ->back()
                ->withInput()
                ->with('error', __('admin.cannot_demote_self'));
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        if ($request->user()->is($user) && ((isset($data['email']) && $data['email'] !== $user->email) || isset($data['password']))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['email' => __('security.use_profile')]);
        }
        $revoke = isset($data['password']) || ($data['email'] ?? $user->email) !== $user->email
            || ($data['role'] ?? $user->role) !== $user->role;
        $user->fill($data);
        if ($revoke) {
            app(\App\Services\Security\RevokeCredentials::class)->all($user);
        } else {
            $user->save();
        }

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('success', __('admin.user_updated'));
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            return redirect()
                ->route('admin.users.index')
                ->with('error', __('admin.cannot_delete_self'));
        }

        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('success', __('admin.user_deleted'));
    }
}
