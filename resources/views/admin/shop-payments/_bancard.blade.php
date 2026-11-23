{{--
    Bancard on Admin > Payments and shipping (PLAN.md E3.4), from Admin\ShopPaymentsController::bancardState(): status,
    last contact, confirmation URL to copy, active environment, the keys of each environment, "Test connection", the
    step-by-step guide and what Bancard asks of the site. A saved key is NEVER printed: only "configured" and the last
    4 characters of the public key; key inputs always render empty (an empty field keeps the stored key).
--}}
@php
    $state = $bancard;
    $environments = \App\Models\PaymentMethod::ENVIRONMENTS;
    $lastCall = $state['lastCall'];
    $call = $lastCall?->payload_json ?? [];
    $callKey = is_string($call['key'] ?? null) ? $call['key'] : null;
    $callText = match (true) {
        $lastCall === null => null,
        ($call['operation'] ?? null) === \App\Http\Controllers\Admin\ShopPaymentsController::TEST_OPERATION && ($call['result'] ?? null) === 'accepted' => __('checkout.bancard_test_ok'),
        ($call['operation'] ?? null) === \App\Http\Controllers\Admin\ShopPaymentsController::TEST_OPERATION && ($call['result'] ?? null) === 'rejected' => __('checkout.bancard_test_rejected', ['reason' => \App\Shop\Payments\Bancard\BancardException::messageFor($callKey ?? 'unexpected')]),
        ($call['operation'] ?? null) === \App\Http\Controllers\Admin\ShopPaymentsController::TEST_OPERATION => __('checkout.bancard_test_failed', ['reason' => \App\Shop\Payments\Bancard\BancardException::messageFor($callKey ?? 'unexpected')]),
        default => trim((is_string($call['operation'] ?? null) ? $call['operation'] : '').($callKey ? ' · '.\App\Shop\Payments\Bancard\BancardException::messageFor($callKey, $callKey) : '')),
    };
@endphp
<section id="bancard" class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 space-y-5" data-bancard data-bancard-status="{{ $state['status'] }}">
    <header class="flex flex-wrap items-center gap-3">
        <span class="shrink-0 w-10 h-10 rounded-lg bg-white/[0.04] text-sky-400 flex items-center justify-center">
            @include('admin.connectors.icons.bancard', ['class' => 'w-6 h-6'])
        </span>
        <div class="flex-1">
            <h2 class="text-base font-semibold text-white">{{ __('checkout.bancard') }}</h2>
            <p class="text-sm text-gray-500">{{ __('checkout.connector_bancard_description') }}</p>
        </div>
        <span class="px-2 py-0.5 rounded-full text-[11px] font-medium ring-1 {{ $state['status'] === 'unconfigured' ? 'bg-yellow-500/10 text-yellow-400 ring-yellow-500/20' : ($state['status'] === 'production' ? 'bg-emerald-500/10 text-emerald-400 ring-emerald-500/20' : 'bg-sky-500/10 text-sky-400 ring-sky-500/20') }}" data-bancard-chip>
            {{ __('checkout.bancard_status_'.$state['status']) }}
        </span>
    </header>

    @if ($state['foreign'])
        <div class="p-3 rounded-md bg-amber-500/10 ring-1 ring-amber-500/20 text-sm text-amber-300" role="alert" data-bancard-reload>
            {{ __('checkout.bancard_reload_keys') }}
        </div>
    @endif

    {{-- Last contact with Bancard --}}
    <dl class="grid gap-3 sm:grid-cols-2 text-sm">
        <div>
            <dt class="text-xs text-gray-500">{{ __('checkout.bancard_last_event') }}</dt>
            <dd class="text-gray-300 mt-0.5" data-bancard-last-event>
                @if ($state['lastEvent'])
                    {{ $state['lastEvent']->created_at->format('d/m/Y H:i') }}
                    @if ($state['lastEvent']->token_valid !== null)
                        · <span class="{{ $state['lastEvent']->token_valid ? 'text-emerald-400' : 'text-red-400' }}">{{ $state['lastEvent']->token_valid ? __('checkout.event_token_valid') : __('checkout.event_token_invalid') }}</span>
                    @endif
                @else
                    {{ __('checkout.bancard_last_event_none') }}
                @endif
            </dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500">{{ __('checkout.bancard_last_call') }}</dt>
            <dd class="text-gray-300 mt-0.5" data-bancard-last-call>
                @if ($lastCall)
                    {{ $lastCall->created_at->format('d/m/Y H:i') }} · {{ $callText }}
                @else
                    —
                @endif
            </dd>
        </div>
    </dl>

    {{-- Confirmation URL (webhook) to paste in the Bancard portal --}}
    <div>
        <label for="bancard-webhook-url" class="block text-sm font-medium text-gray-300">{{ __('checkout.bancard_webhook_url') }}</label>
        <p class="text-xs text-gray-500 mt-0.5">{{ __('checkout.bancard_webhook_help') }}</p>
        <div class="mt-2 flex gap-2">
            <input id="bancard-webhook-url" type="text" readonly value="{{ $state['webhookUrl'] }}" class="{{ $input }} font-mono">
            <button type="button" data-copy-target="bancard-webhook-url" data-copied="{{ __('checkout.copied') }}"
                    class="shrink-0 px-3 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-white text-sm font-medium rounded-md transition-colors">
                <span data-copy-label aria-live="polite">{{ __('checkout.copy') }}</span>
            </button>
        </div>
        @if ($state['webhookUnreachable'])
            <p class="text-xs text-amber-400 mt-2" data-bancard-https-warning>{{ __('checkout.bancard_https_warning') }}</p>
        @endif
    </div>

    {{-- Active environment --}}
    <div>
        <p class="text-sm font-medium text-gray-300">{{ __('checkout.bancard_environment') }}</p>
        <div class="mt-2 flex flex-wrap items-center gap-2" data-bancard-environment="{{ $state['environment'] }}">
            @foreach ($environments as $environment)
                @if ($environment === $state['environment'])
                    <span class="px-3 py-2 rounded-md text-sm font-medium bg-sky-600 text-white" aria-current="true">{{ __('checkout.environment_'.$environment) }}</span>
                @else
                    <form method="POST" action="{{ route('admin.shop-payments.environment') }}"
                          @if ($environment === 'production')
                              data-confirm="{{ __('checkout.bancard_production_confirm') }}"
                              data-confirm-title="{{ __('checkout.environment_production') }}"
                              data-confirm-label="{{ __('checkout.environment_production') }}"
                          @endif>
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="environment" value="{{ $environment }}">
                        @if ($environment === 'production')
                            <input type="hidden" name="confirm" value="1">
                        @endif
                        <button type="submit" @disabled($environment === 'production' && ! $state['keys']['production']['configured'])
                                class="px-3 py-2 rounded-md text-sm font-medium bg-white/5 hover:bg-white/10 border border-white/10 text-gray-200 disabled:opacity-50 disabled:cursor-not-allowed">
                            {{ __('checkout.environment_'.$environment) }}
                        </button>
                    </form>
                @endif
            @endforeach
        </div>
        @if (! $state['keys']['production']['configured'])
            <p class="text-xs text-gray-500 mt-2">{{ __('checkout.bancard_production_requires_keys') }}</p>
        @endif
        @if ($state['pendingOther'] > 0)
            <p class="text-xs text-amber-400 mt-2">{{ trans_choice('checkout.bancard_pending_other_environment', $state['pendingOther'], ['count' => $state['pendingOther'], 'environment' => __('checkout.environment_'.$state['otherEnvironment'])]) }}</p>
        @endif
    </div>

    {{-- Keys of each environment --}}
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($environments as $environment)
            @php $keys = $state['keys'][$environment]; @endphp
            <div class="rounded-lg ring-1 ring-white/[0.06] p-4 space-y-3" data-bancard-keys="{{ $environment }}" data-configured="{{ $keys['configured'] ? '1' : '0' }}">
                <div class="flex items-center justify-between gap-2">
                    <h3 class="text-sm font-semibold text-white">{{ __('checkout.environment_'.$environment) }}</h3>
                    <span class="text-xs {{ $keys['configured'] ? 'text-emerald-400' : 'text-gray-500' }}">
                        {{ $keys['configured'] ? __('checkout.bancard_key_saved', ['last' => $keys['suffix']]) : __('checkout.bancard_status_unconfigured') }}
                    </span>
                </div>
                <form method="POST" action="{{ route('admin.shop-payments.bancard') }}" class="space-y-3" autocomplete="off">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="environment" value="{{ $environment }}">
                    <label class="block">
                        <span class="block text-xs font-medium text-gray-400 mb-1">{{ __('checkout.bancard_public_key') }}</span>
                        <input type="text" name="public_key" value="" maxlength="32" autocomplete="off" spellcheck="false" class="{{ $input }} font-mono">
                    </label>
                    <label class="block">
                        <span class="block text-xs font-medium text-gray-400 mb-1">{{ __('checkout.bancard_private_key') }}</span>
                        <input type="password" name="private_key" value="" autocomplete="new-password" spellcheck="false" class="{{ $input }} font-mono">
                        @if ($keys['configured'])
                            <span class="block text-xs text-gray-500 mt-1">{{ __('checkout.bancard_private_key_help') }}</span>
                        @endif
                    </label>
                    <button type="submit" class="px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">{{ __('checkout.save') }}</button>
                </form>
                @if ($keys['configured'])
                    <div class="flex flex-wrap gap-2 pt-1">
                        <form method="POST" action="{{ route('admin.shop-payments.bancard-test') }}">
                            @csrf
                            <input type="hidden" name="environment" value="{{ $environment }}">
                            <button type="submit" class="px-3 py-2 bg-white/5 hover:bg-white/10 border border-white/10 text-white text-sm font-medium rounded-md transition-colors">{{ __('checkout.bancard_test_connection') }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.shop-payments.bancard') }}"
                              data-confirm="{{ __('checkout.bancard_clear_keys_confirm', ['environment' => __('checkout.environment_'.$environment)]) }}">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="environment" value="{{ $environment }}">
                            <input type="hidden" name="clear" value="1">
                            <button type="submit" class="px-3 py-2 text-sm text-red-400 hover:text-red-300">{{ __('checkout.bancard_clear_keys') }}</button>
                        </form>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @include('admin.shop-payments._bancard-guide')
</section>
