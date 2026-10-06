@extends('admin.layouts.guest')

@section('title', __('admin.sign_in'))

@section('content')
<div class="mb-8 text-center">
    <div class="inline-flex items-center gap-2 mb-6">
        <span class="w-2.5 h-2.5 rounded-full bg-sky-500"></span>
        <span class="text-base font-semibold text-white tracking-tight">{{ $siteName }}</span>
    </div>
    <h1 class="text-xl font-semibold text-white">{{ __('admin.sign_in') }}</h1>
    <p class="text-sm text-gray-500 mt-1">{{ __('admin.sign_in_subtitle') }}</p>
</div>

<div class="bg-[#141414] ring-1 ring-white/[0.08] rounded-xl p-6">
    <form method="POST" action="{{ route('admin.login.submit') }}">
        @csrf

        <div class="mb-4">
            <label for="login" class="block text-xs font-medium text-gray-400 mb-1.5">{{ __('admin.login_identity') }}</label>
            <input
                type="text"
                id="login"
                name="login"
                value="{{ old('login', old('email')) }}"
                required
                autofocus
                autocomplete="username"
                autocapitalize="none"
                spellcheck="false"
                class="w-full px-3 py-2 bg-[#1a1a1a] border border-white/10 rounded-md text-sm text-white placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50 transition-colors"
            >
            @error('login')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-5">
            <label for="password" class="block text-xs font-medium text-gray-400 mb-1.5">{{ __('admin.password') }}</label>
            <input
                type="password"
                id="password"
                autocomplete="current-password"
                name="password"
                required
                class="w-full px-3 py-2 bg-[#1a1a1a] border border-white/10 rounded-md text-sm text-white placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50 transition-colors"
            >
        </div>

        <div class="flex items-center justify-between mb-5">
            <div class="flex items-center">
                <input
                    type="checkbox"
                    id="remember"
                    name="remember"
                    class="h-3.5 w-3.5 rounded border-white/20 bg-[#1a1a1a] text-sky-500 focus:ring-sky-500/50"
                >
                <label for="remember" class="ml-2 text-xs text-gray-500">{{ __('admin.remember_me') }}</label>
            </div>
            <a href="{{ route('admin.password.request') }}" class="text-xs text-gray-400 hover:text-sky-400">
                {{ __('admin.forgot_password_link') }}
            </a>
        </div>

        <button
            type="submit"
            class="w-full py-2 px-4 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:ring-offset-2 focus:ring-offset-[#141414]"
        >
            {{ __('admin.sign_in') }}
        </button>
    </form>
</div>
@endsection
