@extends('admin.layouts.app')

@section('title', __('admin.profile'))

@section('content')
@php
    $roleKey = 'admin.role_' . $user->role;
    $roleLabel = __($roleKey) !== $roleKey ? __($roleKey) : ucfirst($user->role);
@endphp

<div class="mb-6">
    <h1 class="text-lg font-semibold text-white">{{ __('admin.profile') }}</h1>
    <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.profile_subtitle') }}</p>
</div>

<div class="max-w-3xl">
    <div class="flex gap-1 mb-6 border-b border-white/[0.06]">
        <button type="button"
                class="profile-tab px-3 py-2 text-sm font-medium border-b-2 transition-colors"
                data-tab="information"
                onclick="switchProfileTab('information')">
            {{ __('admin.profile_tab_information') }}
        </button>
        <button type="button"
                class="profile-tab px-3 py-2 text-sm font-medium border-b-2 transition-colors"
                data-tab="security"
                onclick="switchProfileTab('security')">
            {{ __('admin.profile_tab_security') }}
        </button>
    </div>

    <section class="profile-panel" data-tab="information" style="display:none">
        <form method="POST" action="{{ route('admin.profile.information.update', ['tab' => 'information']) }}"
              class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
            @csrf
            @method('PUT')

            <h2 class="text-sm font-semibold text-white mb-4">{{ __('admin.profile_identity') }}</h2>

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
                    <label class="block text-sm font-medium text-gray-300 mb-1">{{ __('admin.field_role') }}</label>
                    <p class="text-sm text-gray-400 bg-[#1a1a1a] border border-white/10 rounded-md px-3 py-2">{{ $roleLabel }}</p>
                </div>
            </div>

            <div class="mt-5">
                <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                    {{ __('admin.btn_save_changes') }}
                </button>
            </div>
        </form>
    </section>

    <section class="profile-panel" data-tab="security" style="display:none">
        <form method="POST" action="{{ route('admin.profile.password.update', ['tab' => 'security']) }}"
              class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
            @csrf
            @method('PUT')

            <h2 class="text-sm font-semibold text-white mb-1">{{ __('admin.profile_change_password') }}</h2>
            <p class="text-xs text-gray-500 mb-4">{{ __('admin.profile_change_password_hint') }}</p>

            <div class="grid sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <label for="current_password" class="block text-sm font-medium text-gray-300 mb-1">{{ __('admin.field_current_password') }}</label>
                    <input type="password" id="current_password" name="current_password"
                           class="w-full sm:max-w-sm bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                    @error('current_password') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password" class="block text-sm font-medium text-gray-300 mb-1">{{ __('admin.field_new_password') }}</label>
                    <input type="password" id="password" name="password"
                           class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                    @error('password') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-300 mb-1">{{ __('admin.field_confirm_password') }}</label>
                    <input type="password" id="password_confirmation" name="password_confirmation"
                           class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
                </div>
            </div>

            <div class="mt-5">
                <button type="submit" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                    {{ __('admin.btn_update_password') }}
                </button>
            </div>
        </form>
    </section>
</div>

@push('scripts')
<script>
    const profileTabs = document.querySelectorAll('.profile-tab');
    const profilePanels = document.querySelectorAll('.profile-panel');
    const profileParams = new URLSearchParams(window.location.search);
    const profileInitialTab = profileParams.get('tab') || 'information';

    function switchProfileTab(tab) {
        profileTabs.forEach(button => {
            if (button.dataset.tab === tab) {
                button.classList.add('border-sky-500', 'text-sky-400');
                button.classList.remove('border-transparent', 'text-gray-500', 'hover:text-gray-300');
            } else {
                button.classList.remove('border-sky-500', 'text-sky-400');
                button.classList.add('border-transparent', 'text-gray-500', 'hover:text-gray-300');
            }
        });

        profilePanels.forEach(panel => {
            panel.style.display = panel.dataset.tab === tab ? '' : 'none';
        });
    }

    if (profileTabs.length) {
        const validInitialTab = Array.from(profileTabs).some(button => button.dataset.tab === profileInitialTab);
        switchProfileTab(validInitialTab ? profileInitialTab : 'information');
    }
</script>
@endpush
@endsection
