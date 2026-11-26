@extends('admin.layouts.app')

@section('title', __('checkout.payments_title'))

@section('content')
{{--
    Admin > Payments and shipping (PLAN.md E3.4), from Admin\ShopPaymentsController::edit(). One main form
    (#shop-payments-form: methods, orders email, purchase policies page and, from _shipping, store pickup); delivery
    zones and the Bancard keys have their own forms. Saved Bancard keys are never printed: only "configured" and the
    last 4 characters of the public key.
--}}
@php
    // Requirements solved on this same screen jump to their section; the rest open their own screen.
    $anchors = ['payment_method' => '#methods', 'delivery' => '#shipping', 'orders_email' => '#orders-email'];
    $input = 'w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50';
@endphp
<div class="mb-6 flex items-start justify-between gap-4">
    <div>
        <h1 class="text-lg font-semibold text-white">{{ __('checkout.payments_title') }}</h1>
        <p class="text-sm text-gray-500 mt-0.5">{{ __('checkout.payments_intro') }}</p>
    </div>
    <button type="submit" form="shop-payments-form"
            class="shrink-0 px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
        {{ __('checkout.save') }}
    </button>
</div>

@if ($errors->any())
    <div class="mb-4 p-3.5 bg-red-500/10 border border-red-500/20 text-red-300 rounded-md text-sm space-y-1" role="alert" data-payments-errors>
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

@include('admin.shop._mail_notice')

<div class="space-y-6 max-w-4xl">
    {{-- What selling online still needs (CheckoutReadiness), each with where to solve it. --}}
    <section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" data-readiness>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-white">{{ __('checkout.readiness_title') }}</h2>
            <a href="{{ route('admin.settings.shop') }}" class="text-sm text-sky-400 hover:text-sky-300">{{ __('admin.settings') }} › {{ __('shop.module_title') }}</a>
        </div>
        <ul class="mt-3 space-y-2 text-sm">
            @foreach ($readiness as $item)
                <li class="flex items-start gap-2" data-readiness-item="{{ $item->key }}" data-met="{{ $item->met ? '1' : '0' }}">
                    <span class="mt-0.5 {{ $item->met ? 'text-emerald-400' : 'text-amber-400' }}" aria-hidden="true">{{ $item->met ? '✓' : '•' }}</span>
                    <span class="flex-1 {{ $item->met ? 'text-gray-400' : 'text-gray-200' }}">{{ ucfirst($item->label()) }}</span>
                    @unless ($item->met)
                        <a href="{{ $anchors[$item->key] ?? $item->url() }}" class="text-sky-400 hover:text-sky-300 whitespace-nowrap">{{ __('checkout.readiness_fix') }}</a>
                    @endunless
                </li>
            @endforeach
        </ul>
        @if (collect($readiness)->every(fn ($item) => $item->met))
            <p class="text-sm text-emerald-400 mt-3">{{ __('checkout.readiness_all_met') }}</p>
        @endif
    </section>

    <form id="shop-payments-form" method="POST" action="{{ route('admin.shop-payments.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        {{-- Payment methods --}}
        <section id="methods" class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5">
            <h2 class="text-base font-semibold text-white">{{ __('checkout.payment_methods') }}</h2>
            <div class="mt-4 space-y-4">
                @foreach ($methods as $row)
                    @php
                        $method = $row['method'];
                        $key = $method->key;
                    @endphp
                    <div class="rounded-lg ring-1 ring-white/[0.06] p-4" data-method="{{ $key }}">
                        <div class="flex flex-wrap items-center gap-3">
                            <h3 class="text-sm font-semibold text-white flex-1">{{ __('checkout.method_'.$key) }}</h3>
                            @if ($row['missing'] === [])
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-medium ring-1 bg-emerald-500/10 text-emerald-400 ring-emerald-500/20">{{ __('checkout.method_ready') }}</span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-medium ring-1 bg-yellow-500/10 text-yellow-400 ring-yellow-500/20" data-method-missing>{{ __('checkout.method_incomplete', ['fields' => implode(', ', $row['missing'])]) }}</span>
                            @endif
                            <label class="inline-flex items-center gap-2 text-sm text-gray-300">
                                <input type="hidden" name="methods[{{ $key }}][enabled]" value="0">
                                <input type="checkbox" name="methods[{{ $key }}][enabled]" value="1" @checked(old('methods.'.$key.'.enabled', $method->enabled)) class="rounded border-white/20 bg-[#1a1a1a] text-sky-600">
                                {{ __('checkout.method_enabled') }}
                            </label>
                            <label class="inline-flex items-center gap-2 text-sm text-gray-400">
                                {{ __('checkout.method_sort_order') }}
                                <input type="number" min="0" max="999" name="methods[{{ $key }}][sort_order]" value="{{ old('methods.'.$key.'.sort_order', $method->sort_order) }}" class="w-20 {{ $input }}">
                            </label>
                        </div>

                        @if ($row['manual'])
                            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                                @foreach ($row['fields'] as $field)
                                    @php
                                        $name = 'methods['.$key.'][settings]['.$field.']';
                                        $value = old('methods.'.$key.'.settings.'.$field, $method->setting($field, ''));
                                    @endphp
                                    <label class="block {{ $field === 'instructions' ? 'sm:col-span-2' : '' }}">
                                        <span class="block text-xs font-medium text-gray-400 mb-1">
                                            {{ __('checkout.'.$field) }}@if (in_array($field, $row['required'], true)) <span class="text-amber-400">*</span>@endif
                                        </span>
                                        @if ($field === 'instructions')
                                            <textarea name="{{ $name }}" rows="3" class="{{ $input }}">{{ $value }}</textarea>
                                            @if ($key === 'cod')
                                                <span class="block text-xs text-gray-500 mt-1">{{ __('checkout.cod_instructions_help') }}</span>
                                            @endif
                                        @else
                                            <input type="text" name="{{ $name }}" value="{{ $value }}" maxlength="255" class="{{ $input }}">
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        @elseif ($key === 'bancard' && $bancard)
                            <p class="mt-3 text-sm text-gray-400">
                                {{ __('checkout.bancard_status') }}: <span class="text-gray-200">{{ __('checkout.bancard_status_'.$bancard['status']) }}</span>
                                · <a href="#bancard" class="text-sky-400 hover:text-sky-300">{{ __('checkout.connector_bancard_configure') }}</a>
                            </p>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Orders email and purchase policies page --}}
        <section id="orders-email" class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="block text-sm font-medium text-gray-300 mb-1">{{ __('checkout.orders_email') }}</span>
                <input type="email" name="shop_orders_email" value="{{ old('shop_orders_email', $ordersEmail) }}" placeholder="{{ $contactEmail }}" class="{{ $input }}">
                <span class="block text-xs text-gray-500 mt-1">{{ __('checkout.orders_email_help') }}</span>
            </label>
            <label class="block">
                <span class="block text-sm font-medium text-gray-300 mb-1">{{ __('checkout.terms_page') }}</span>
                <select name="shop_terms_page_id" class="{{ $input }}">
                    <option value="">{{ __('checkout.terms_page_none') }}</option>
                    @foreach ($pages as $page)
                        <option value="{{ $page->id }}" @selected((string) old('shop_terms_page_id', $termsPageId) === (string) $page->id)>{{ $page->title }} (/{{ ltrim($page->slug, '/') }})</option>
                    @endforeach
                </select>
            </label>
        </section>
    </form>

    @include('admin.shop-payments._shipping')

    @if ($bancard)
        @include('admin.shop-payments._bancard')
    @endif
</div>
@endsection

@push('scripts')
<script>
// "Copy" buttons (Bancard confirmation URL): clipboard API, with a selection fallback for plain http sites.
document.querySelectorAll('[data-copy-target]').forEach((button) => {
    const label = button.querySelector('[data-copy-label]');
    const original = label.textContent;
    button.addEventListener('click', async () => {
        const input = document.getElementById(button.dataset.copyTarget);
        let copied = false;
        try {
            await navigator.clipboard.writeText(input.value);
            copied = true;
        } catch (error) {
            input.select();
            copied = document.execCommand('copy');
        }
        if (copied) {
            label.textContent = button.dataset.copied;
            button.classList.add('text-emerald-400');
            setTimeout(() => {
                label.textContent = original;
                button.classList.remove('text-emerald-400');
            }, 2000);
        }
    });
});
</script>
@endpush
