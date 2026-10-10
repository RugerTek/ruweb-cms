@extends('admin.layouts.app')

@section('title', __('admin.edit_user_title'))

@section('content')
<div class="mb-6">
    <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">
        <a href="{{ route('admin.users.index') }}" class="hover:text-gray-300 transition-colors">{{ __('admin.users') }}</a>
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <span class="text-gray-400">{{ $user->name }}</span>
    </div>
    <h1 class="text-lg font-semibold text-white">{{ __('admin.edit_user_title') }}</h1>
</div>

<form method="POST" action="{{ route('admin.users.update', $user) }}" class="max-w-3xl space-y-4">
    @csrf
    @method('PUT')

    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label for="name" class="block text-sm font-medium text-gray-300 mb-1">{{ __('admin.field_name') }}</label>
                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required
                       class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                @error('name') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            @include('admin.users._username-field', ['user' => $user ?? null])

            <div>
                <label for="email" class="block text-sm font-medium text-gray-300 mb-1">{{ __('admin.field_email') }}</label>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required
                       class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                @error('email') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="role" class="block text-sm font-medium text-gray-300 mb-1">{{ __('admin.field_role') }}</label>
                <select id="role" name="role" required
                        class="w-full bg-[#1a1a1a] border border-white/10 text-gray-400 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                    @foreach ($roles as $role)
                        @php
                            $roleKey = 'admin.role_' . $role;
                            $roleLabel = __($roleKey) !== $roleKey ? __($roleKey) : ucfirst($role);
                        @endphp
                        <option value="{{ $role }}" @selected(old('role', $user->role) === $role)>{{ $roleLabel }}</option>
                    @endforeach
                </select>
                @error('role') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            @include('admin.users._custom-code-permission')

            <div>
                <label for="password" class="block text-sm font-medium text-gray-300 mb-1">{{ __('admin.field_new_password') }}</label>
                <input type="password" id="password" name="password"
                       class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                <p class="text-xs text-gray-500 mt-1">{{ __('admin.user_password_optional') }}</p>
                @error('password') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-gray-300 mb-1">{{ __('admin.field_confirm_password') }}</label>
                <input type="password" id="password_confirmation" name="password_confirmation"
                       class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
            </div>
        </div>
    </div>

    <div class="flex items-center justify-between gap-4">
        <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
            {{ __('admin.btn_save_changes') }}
        </button>

        @if (! auth()->user()->is($user))
            <button type="button"
                    onclick="document.getElementById('delete-user-form').requestSubmit()"
                    class="px-4 py-2 bg-transparent border border-red-500/30 hover:border-red-500/50 text-red-300 text-sm font-medium rounded-md transition-colors">
                {{ __('admin.btn_delete_user') }}
            </button>
        @endif
    </div>
</form>

@if (! auth()->user()->is($user))
    <form id="delete-user-form" method="POST" action="{{ route('admin.users.destroy', $user) }}"
          onsubmit="return confirm('{{ __('admin.confirm_delete_user') }}')">
        @csrf
        @method('DELETE')
    </form>
@endif
@endsection
