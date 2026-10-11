<div class="mt-5 space-y-5 text-sm text-gray-300">
    @if(session('recovery_codes'))
        <div role="status" class="bg-sky-950 border border-sky-500/30 rounded-xl p-5">
            <h2 class="font-semibold mb-3">{{ __('security.recovery_codes') }}</h2>
            <ul class="grid grid-cols-2 gap-2 font-mono">@foreach(session('recovery_codes') as $code)<li>{{ $code }}</li>@endforeach</ul>
        </div>
    @endif
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
        <h2 class="font-semibold text-white">{{ __('security.2fa') }}</h2>
        <p class="text-xs text-gray-400 my-3">{{ __('security.2fa_hint') }}</p>
        @if($user->hasTwoFactor())
            <form method="POST" action="{{ route('admin.two-factor.disable') }}" class="space-y-3">
                @csrf @method('DELETE')
                @include('admin.profile._security-proof', ['prefix' => 'disable', 'withCode' => true])
                <button type="submit" class="border border-red-500/40 text-red-300 px-3 py-2 rounded-md">{{ __('security.disable') }}</button>
            </form>
            <form method="POST" action="{{ route('admin.two-factor.recovery') }}" class="mt-4 space-y-3">
                @csrf
                @include('admin.profile._security-proof', ['prefix' => 'recovery', 'withCode' => true])
                <button type="submit" class="bg-sky-600 text-white px-3 py-2 rounded-md">{{ __('security.regenerate_recovery') }}</button>
            </form>
        @elseif($user->two_factor_secret)
            <p class="text-xs mb-2">{{ __('security.setup_hint') }}</p>
            <label for="setup-key" class="block mb-2">{{ __('security.setup_key') }}</label>
            <input readonly id="setup-key" value="{{ $user->two_factor_secret }}" class="w-full bg-[#1a1a1a] px-3 py-2 font-mono rounded-md mb-3 select-all">
            <form method="POST" action="{{ route('admin.two-factor.confirm', ['tab' => 'security']) }}" class="space-y-3">
                @csrf
                <input type="hidden" name="_profile_tab" value="security">
                <label for="confirm-code" class="block">{{ __('security.code') }}</label>
                <input id="confirm-code" name="code" required autocomplete="one-time-code" inputmode="numeric" class="bg-[#1a1a1a] border border-white/10 rounded-md px-3 py-2">
                @error('code') <p class="text-red-400" role="alert">{{ $message }}</p> @enderror
                <button type="submit" class="bg-sky-600 text-white px-3 py-2 rounded-md">{{ __('security.confirm') }}</button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.two-factor.enable') }}" class="space-y-3">
                @csrf
                @include('admin.profile._security-proof', ['prefix' => 'enable', 'withCode' => false])
                <button type="submit" class="bg-sky-600 text-white px-3 py-2 rounded-md">{{ __('security.enable') }}</button>
            </form>
        @endif
    </section>
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
        <h2 class="font-semibold text-white mb-3">{{ __('security.sessions') }}</h2>
        <ul class="space-y-3">
        @foreach($sessions as $session)
            <li class="border-b border-white/10 pb-3 break-words">
                <p>{{ $session->ip_address }} · {{ $session->user_agent }}</p>
                <p class="text-xs text-gray-400">{{ __('security.last_active') }}: {{ $session->last_active_at }}</p>
                @if($session->id === $currentSession)
                    <span class="text-sky-400">{{ __('security.current_session') }}</span>
                @else
                    <form method="POST" action="{{ route('admin.profile.sessions.destroy', $session->id) }}" class="mt-2 space-y-2">
                        @csrf @method('DELETE')
                        @include('admin.profile._security-proof', ['prefix' => 'session-'.$loop->index, 'withCode' => false])
                        <button type="submit" class="text-red-300 border border-red-500/30 px-3 py-2 rounded-md">{{ __('security.revoke') }}</button>
                    </form>
                @endif
            </li>
        @endforeach
        </ul>
        <form method="POST" action="{{ route('admin.profile.sessions.others') }}" class="mt-4 space-y-2">
            @csrf @method('DELETE')
            @include('admin.profile._security-proof', ['prefix' => 'others', 'withCode' => false])
            <button type="submit" class="border border-white/20 px-3 py-2 rounded-md">{{ __('security.revoke_others') }}</button>
        </form>
    </section>
</div>
