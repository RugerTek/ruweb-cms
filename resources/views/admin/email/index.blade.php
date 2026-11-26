@extends('admin.layouts.app')

@section('title', __('admin.email'))

@section('content')
{{--
    Admin > Email (docs/operations/email.md). The provider radio shows the Brevo or SMTP fields; secrets are
    write-only (empty field = keep the stored one). The status box comes from App\Services\Mail\MailSettings::summary():
    off, incomplete or active, plus the last delivery error without secrets.
--}}
@php
    $input = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
    $provider = old('settings.mail_provider', $values['mail_provider'] ?: 'brevo');
    $field = fn (string $key) => __('admin.settings_fields.'.$key.'.label');
    $help = fn (string $key) => __('admin.settings_fields.'.$key.'.help');
    $providerLabel = __('admin.email_provider_'.$status['provider']);
@endphp
<div class="mb-6 flex items-start justify-between gap-4">
    <div>
        <h1 class="text-lg font-semibold text-white">{{ __('admin.email') }}</h1>
        <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.email_subtitle') }}</p>
    </div>
    <button type="submit" form="email-form"
            class="shrink-0 px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
        {{ __('admin.btn_save_changes') }}
    </button>
</div>

<div class="mb-6 rounded-xl p-4 text-sm ring-1 {{ $status['active'] ? 'bg-emerald-500/10 ring-emerald-500/20 text-emerald-400' : 'bg-amber-500/10 ring-amber-500/20 text-amber-300' }}"
     data-mail-status="{{ $status['active'] ? 'active' : ($status['enabled'] ? 'incomplete' : 'disabled') }}">
    @if ($status['active'])
        <p>{{ __('admin.email_status_active', ['provider' => $providerLabel]) }}</p>
    @elseif ($status['enabled'])
        <p>{{ __('admin.email_status_incomplete', ['provider' => $providerLabel]) }}</p>
    @else
        <p>{{ __('admin.email_status_disabled') }}</p>
    @endif
    @if ($status['last_error'])
        <p class="mt-2 text-red-400" data-mail-last-error>
            {{ __('admin.email_status_last_error', ['date' => \Illuminate\Support\Carbon::parse($status['last_error']['at'])->timezone(config('app.timezone'))->format('d/m/Y H:i')]) }}
            <span class="font-mono break-words">{{ $status['last_error']['message'] }}</span>
        </p>
    @endif
</div>

<form id="email-form" method="POST" action="{{ route('admin.email.update') }}" class="space-y-4">
    @csrf
    @method('PUT')

    {{-- Switch and provider --}}
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-4 space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">{{ $field('mail_enabled') }}</label>
            <p class="text-xs text-gray-500 mb-2">{{ $help('mail_enabled') }}</p>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="hidden" name="settings[mail_enabled]" value="0">
                <input type="checkbox" name="settings[mail_enabled]" value="1" @checked(old('settings.mail_enabled', $values['mail_enabled'])) class="sr-only peer">
                <div class="w-9 h-5 bg-gray-700 peer-focus:ring-2 peer-focus:ring-sky-500/50 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-sky-600"></div>
            </label>
        </div>
        <fieldset>
            <legend class="block text-sm font-medium text-gray-300 mb-1">{{ $field('mail_provider') }}</legend>
            <p class="text-xs text-gray-500 mb-2">{{ $help('mail_provider') }}</p>
            <div class="flex flex-wrap gap-4">
                @foreach ($providers as $option)
                    <label class="inline-flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                        <input type="radio" name="settings[mail_provider]" value="{{ $option }}" @checked($provider === $option) data-mail-provider-choice>
                        {{ __('admin.email_provider_'.$option) }}
                    </label>
                @endforeach
            </div>
        </fieldset>
    </div>

    {{-- Brevo --}}
    <div class="space-y-4 {{ $provider === 'brevo' ? '' : 'hidden' }}" data-mail-provider-section="brevo">
        @include('admin.settings._email_instructions')
        <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-4">
            <label for="brevo_api_key" class="block text-sm font-medium text-gray-300 mb-1">{{ $field('brevo_api_key') }}</label>
            <p class="text-xs text-gray-500 mb-2">{{ $help('brevo_api_key') }}</p>
            <input id="brevo_api_key" type="password" name="settings[brevo_api_key]" autocomplete="new-password"
                   placeholder="{{ $stored['brevo_api_key'] ? __('admin.password_stored_placeholder') : '' }}" class="{{ $input }}">
            @if ($stored['brevo_api_key'])
                <p class="mt-1.5 text-xs text-gray-500">{{ __('admin.password_stored_hint') }}</p>
            @endif
        </div>
    </div>

    {{-- SMTP --}}
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-4 grid gap-4 sm:grid-cols-2 {{ $provider === 'smtp' ? '' : 'hidden' }}" data-mail-provider-section="smtp">
        <h2 class="sm:col-span-2 text-sm font-semibold text-white">{{ __('admin.email_section_smtp') }}</h2>
        <div>
            <label for="smtp_host" class="block text-sm font-medium text-gray-300 mb-1">{{ $field('smtp_host') }}</label>
            <p class="text-xs text-gray-500 mb-2">{{ $help('smtp_host') }}</p>
            <input id="smtp_host" type="text" name="settings[smtp_host]" value="{{ old('settings.smtp_host', $values['smtp_host']) }}" autocomplete="off" class="{{ $input }}">
            @error('settings.smtp_host')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="smtp_port" class="block text-sm font-medium text-gray-300 mb-1">{{ $field('smtp_port') }}</label>
            <p class="text-xs text-gray-500 mb-2">{{ $help('smtp_port') }}</p>
            <input id="smtp_port" type="number" min="1" max="65535" name="settings[smtp_port]" value="{{ old('settings.smtp_port', $values['smtp_port'] ?: '') }}" class="{{ $input }}">
            @error('settings.smtp_port')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="smtp_encryption" class="block text-sm font-medium text-gray-300 mb-1">{{ $field('smtp_encryption') }}</label>
            <p class="text-xs text-gray-500 mb-2">{{ $help('smtp_encryption') }}</p>
            <select id="smtp_encryption" name="settings[smtp_encryption]" class="{{ $input }}">
                @foreach ($encryptions as $option)
                    <option value="{{ $option }}" @selected(old('settings.smtp_encryption', $values['smtp_encryption'] ?: 'tls') === $option)>{{ __('admin.email_encryption_'.$option) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="smtp_username" class="block text-sm font-medium text-gray-300 mb-1">{{ $field('smtp_username') }}</label>
            <p class="text-xs text-gray-500 mb-2">{{ $help('smtp_username') }}</p>
            <input id="smtp_username" type="text" name="settings[smtp_username]" value="{{ old('settings.smtp_username', $values['smtp_username']) }}" autocomplete="off" class="{{ $input }}">
        </div>
        <div class="sm:col-span-2">
            <label for="smtp_password" class="block text-sm font-medium text-gray-300 mb-1">{{ $field('smtp_password') }}</label>
            <p class="text-xs text-gray-500 mb-2">{{ $help('smtp_password') }}</p>
            <input id="smtp_password" type="password" name="settings[smtp_password]" autocomplete="new-password"
                   placeholder="{{ $stored['smtp_password'] ? __('admin.password_stored_placeholder') : '' }}" class="{{ $input }}">
            @if ($stored['smtp_password'])
                <p class="mt-1.5 text-xs text-gray-500">{{ __('admin.password_stored_hint') }}</p>
            @endif
        </div>
    </div>

    {{-- Sender --}}
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-4 grid gap-4 sm:grid-cols-2">
        <h2 class="sm:col-span-2 text-sm font-semibold text-white">{{ __('admin.email_section_sender') }}</h2>
        <div>
            <label for="mail_from_address" class="block text-sm font-medium text-gray-300 mb-1">{{ $field('mail_from_address') }}</label>
            <p class="text-xs text-gray-500 mb-2">{{ $help('mail_from_address') }}</p>
            <input id="mail_from_address" type="email" name="settings[mail_from_address]" value="{{ old('settings.mail_from_address', $values['mail_from_address']) }}" class="{{ $input }}">
            @error('settings.mail_from_address')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="mail_from_name" class="block text-sm font-medium text-gray-300 mb-1">{{ $field('mail_from_name') }}</label>
            <p class="text-xs text-gray-500 mb-2">{{ $help('mail_from_name') }}</p>
            <input id="mail_from_name" type="text" name="settings[mail_from_name]" value="{{ old('settings.mail_from_name', $values['mail_from_name']) }}" class="{{ $input }}">
        </div>
    </div>
</form>

{{-- Test email --}}
<div class="mt-6">
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-4">
        <h3 class="text-sm font-semibold text-white mb-1">{{ __('admin.email_test_title') }}</h3>
        <p class="text-xs text-gray-500 mb-3">{{ __('admin.email_test_help') }}</p>
        <form method="POST" action="{{ route('admin.email.test') }}" class="flex gap-2">
            @csrf
            <input type="email" name="test_email" required
                   value="{{ auth()->user()->email }}"
                   placeholder="you@example.com"
                   class="flex-1 bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">
            <button type="submit" class="px-4 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-white text-sm font-medium rounded-md transition-colors whitespace-nowrap">
                {{ __('admin.email_test_send') }}
            </button>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Provider radio: show only the fields of the chosen provider (both are submitted; hidden ones keep their values).
document.querySelectorAll('[data-mail-provider-choice]').forEach((radio) => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('[data-mail-provider-section]').forEach((section) => {
            section.classList.toggle('hidden', section.dataset.mailProviderSection !== radio.value);
        });
    });
});
</script>
@endpush
