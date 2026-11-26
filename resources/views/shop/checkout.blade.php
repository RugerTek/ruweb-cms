@extends('layouts.public')

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

@use('App\Models\Order')
@use('App\Services\Shop\CartService')
@use('App\Services\Shop\CheckoutService')

@section('content')
@php
    $subtotal = $cart['subtotal'];
    $hasPickup = $pickupAddress !== null;
    $hasZones = $zones->isNotEmpty();
    $hasDelivery = $hasPickup || $hasZones;
    $selectedDelivery = old('delivery_method', $hasPickup ? Order::DELIVERY_PICKUP : Order::DELIVERY_DELIVERY);
    $selectedZone = (string) old('shipping_zone_id', '');
    $selectedMethod = old('payment_method', $paymentMethods->first()?->key);
    $free = __('checkout.shipping_free');
    $inputClass = 'mt-1 block w-full rounded-md border border-gray-200 px-3 py-2 text-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20';
    $labelClass = 'block text-sm font-medium text-gray-700';
    $chosenZone = $selectedDelivery === Order::DELIVERY_DELIVERY ? $zones->firstWhere('id', (int) $selectedZone) : null;
@endphp
<section class="py-14 sm:py-16" data-shop-checkout>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <a href="{{ CartService::route('cart.index') }}" class="mb-8 inline-flex items-center text-sm text-gray-500 hover:text-gray-800 transition-colors">← {{ __('checkout.cart_title') }}</a>
        <h1 class="text-3xl sm:text-4xl font-semibold tracking-tight text-gray-900">{{ __('checkout.checkout_title') }}</h1>

        @if($errors->any())
            <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert" data-checkout-errors>
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-8 grid grid-cols-1 gap-10 lg:grid-cols-3">
            <form method="POST" action="{{ CartService::route('checkout.store') }}" class="space-y-10 lg:col-span-2" data-checkout-form>
                @csrf
                <x-forms.recaptcha action="checkout" />

                <fieldset>
                    <legend class="text-lg font-semibold text-gray-900">{{ __('checkout.customer_details') }}</legend>
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="customer_name" class="{{ $labelClass }}">{{ __('checkout.customer_name') }}</label>
                            <input id="customer_name" name="customer_name" type="text" required maxlength="120" autocomplete="name" value="{{ old('customer_name') }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="customer_email" class="{{ $labelClass }}">{{ __('checkout.customer_email') }}</label>
                            <input id="customer_email" name="customer_email" type="email" required maxlength="190" autocomplete="email" value="{{ old('customer_email') }}" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label for="customer_phone" class="{{ $labelClass }}">{{ __('checkout.customer_phone') }}</label>
                            <input id="customer_phone" name="customer_phone" type="tel" required maxlength="60" autocomplete="tel" value="{{ old('customer_phone') }}" class="{{ $inputClass }}">
                        </div>
                    </div>
                </fieldset>

                <fieldset data-checkout-delivery>
                    <legend class="text-lg font-semibold text-gray-900">{{ __('checkout.delivery') }}</legend>
                    @if(! $hasDelivery)
                        <p class="mt-4 text-sm text-red-600">{{ __('checkout.no_delivery_options') }}</p>
                    @endif
                    <div class="mt-4 space-y-3">
                        @if($hasPickup)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-4 has-[:checked]:border-gray-900 has-[:checked]:bg-gray-50">
                                <input type="radio" name="delivery_method" value="{{ Order::DELIVERY_PICKUP }}" class="mt-1"
                                       data-shipping-label="{{ $free }}" data-total-label="{{ $subtotal->format() }}"
                                       @checked($selectedDelivery === Order::DELIVERY_PICKUP)>
                                <span>
                                    <span class="block text-sm font-medium text-gray-900">{{ __('checkout.delivery_pickup') }} · {{ __('checkout.delivery_pickup_free') }}</span>
                                    <span class="block text-sm text-gray-600" data-pickup-address>{{ __('checkout.delivery_pickup_address', ['address' => $pickupAddress]) }}</span>
                                </span>
                            </label>
                        @endif
                        @if($hasZones)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-4 has-[:checked]:border-gray-900 has-[:checked]:bg-gray-50">
                                <input type="radio" name="delivery_method" value="{{ Order::DELIVERY_DELIVERY }}" class="mt-1" data-delivery-shipping
                                       @checked($selectedDelivery === Order::DELIVERY_DELIVERY)>
                                <span class="block text-sm font-medium text-gray-900">{{ __('checkout.delivery_shipping') }}</span>
                            </label>
                        @endif
                    </div>

                    @if($hasZones)
                        <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2" data-shipping-fields>
                            <div class="sm:col-span-2">
                                <label for="shipping_zone_id" class="{{ $labelClass }}">{{ __('checkout.shipping_zone') }}</label>
                                <select id="shipping_zone_id" name="shipping_zone_id" class="{{ $inputClass }}">
                                    <option value="">{{ __('checkout.choose_zone') }}</option>
                                    @foreach($zones as $zone)
                                        <option value="{{ $zone->id }}" @selected($selectedZone === (string) $zone->id)
                                                data-shipping-label="{{ $zone->price_amount->isZero() ? $free : $zone->price_amount->format() }}"
                                                data-total-label="{{ $subtotal->add($zone->price_amount)->format() }}">
                                            {{ $zone->name }} — {{ $zone->price_amount->isZero() ? $free : $zone->price_amount->format() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label for="address" class="{{ $labelClass }}">{{ __('checkout.address_line') }}</label>
                                <input id="address" name="address" type="text" maxlength="255" autocomplete="street-address" value="{{ old('address') }}" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label for="city" class="{{ $labelClass }}">{{ __('checkout.address_city') }}</label>
                                <input id="city" name="city" type="text" maxlength="120" autocomplete="address-level2" value="{{ old('city') }}" class="{{ $inputClass }}">
                            </div>
                            <div>
                                <label for="department" class="{{ $labelClass }}">{{ __('checkout.address_state') }}</label>
                                <input id="department" name="department" type="text" maxlength="120" autocomplete="address-level1" value="{{ old('department') }}" class="{{ $inputClass }}">
                            </div>
                            <div class="sm:col-span-2">
                                <label for="reference" class="{{ $labelClass }}">{{ __('checkout.address_reference') }}</label>
                                <input id="reference" name="reference" type="text" maxlength="255" placeholder="{{ __('checkout.address_reference_help') }}" value="{{ old('reference') }}" class="{{ $inputClass }}">
                            </div>
                        </div>
                    @endif
                </fieldset>

                <div>
                    <label for="notes" class="text-lg font-semibold text-gray-900">{{ __('checkout.notes') }}</label>
                    <textarea id="notes" name="notes" rows="3" maxlength="2000" class="{{ $inputClass }}">{{ old('notes') }}</textarea>
                </div>

                <fieldset data-checkout-payment>
                    <legend class="text-lg font-semibold text-gray-900">{{ __('checkout.payment_method') }}</legend>
                    @if($paymentMethods->isEmpty())
                        <p class="mt-4 text-sm text-red-600">{{ __('checkout.no_payment_methods') }}</p>
                    @endif
                    <div class="mt-4 space-y-3">
                        @foreach($paymentMethods as $method)
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 p-4 has-[:checked]:border-gray-900 has-[:checked]:bg-gray-50">
                                <input type="radio" name="payment_method" value="{{ $method->key }}" @checked($selectedMethod === $method->key) data-payment-method="{{ $method->key }}">
                                <span class="text-sm font-medium text-gray-900">{{ CheckoutService::methodLabel($method) }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                @if($termsPage)
                    <label class="flex items-start gap-3 text-sm text-gray-700" data-checkout-terms>
                        <input type="checkbox" name="accept_terms" value="1" class="mt-1" @checked(old('accept_terms'))>
                        <span>{!! __('checkout.accept_terms', ['link' => '<a href="'.e($termsPage->url()).'" target="_blank" rel="noopener" class="font-medium text-sky-700 underline hover:text-sky-800">'.e(__('checkout.terms_link')).'</a>']) !!}</span>
                    </label>
                @endif

                {{-- The anti-spam fields sit next to the button so «Preparando el envío…» shows where the buyer is looking;
                     below lg the summary comes after the form, so the total is repeated here. --}}
                <div class="flex flex-col gap-3 sm:items-start" data-checkout-submit>
                    <p class="flex w-full justify-between text-base font-semibold text-gray-900 lg:hidden" data-checkout-submit-total>
                        <span>{{ __('checkout.total') }}</span>
                        <span data-summary-total-inline>{{ ($chosenZone ? $subtotal->add($chosenZone->price_amount) : $subtotal)->format() }}</span>
                    </p>
                    <x-forms.honeypot :page="(object) ['id' => \App\Http\Requests\Public\CheckoutRequest::PAGE_ID, 'locale' => app()->getLocale()]" :form="\App\Http\Requests\Public\CheckoutRequest::FORM" />
                    <button type="submit" data-place-order @disabled(! $hasDelivery || $paymentMethods->isEmpty())
                            class="inline-flex w-full justify-center rounded-md bg-gray-900 px-4 py-3 text-sm font-medium text-white hover:bg-gray-700 transition-colors disabled:cursor-not-allowed disabled:bg-gray-300 sm:w-auto">
                        {{ __('checkout.place_order') }}
                    </button>
                </div>
            </form>

            <div class="lg:sticky lg:top-6 h-fit">
                @include('shop.partials.order-summary', [
                    'lines' => $cart['lines'],
                    'subtotal' => $subtotal,
                    'shipping' => $chosenZone?->price_amount,
                    'total' => $chosenZone ? $subtotal->add($chosenZone->price_amount) : null,
                    'shippingPending' => $selectedDelivery === Order::DELIVERY_DELIVERY,
                ])
            </div>
        </div>
    </div>
</section>

@push('scripts')
    <script>
        (function () {
            var form = document.querySelector('[data-checkout-form]');
            if (!form) return;
            var fields = form.querySelector('[data-shipping-fields]');
            var zone = form.querySelector('#shipping_zone_id');
            var shipping = document.querySelector('[data-summary-shipping]');
            var total = document.querySelector('[data-summary-total]');
            var inlineTotal = form.querySelector('[data-summary-total-inline]');
            var subtotal = document.querySelector('[data-summary-subtotal]');

            function refresh() {
                var chosen = form.querySelector('input[name="delivery_method"]:checked');
                var source = null;
                var isShipping = chosen && chosen.hasAttribute('data-delivery-shipping');
                if (fields) fields.hidden = !isShipping;
                if (chosen && !isShipping) source = chosen;
                if (isShipping && zone && zone.selectedOptions[0] && zone.value) source = zone.selectedOptions[0];
                shipping.textContent = source ? source.dataset.shippingLabel : '—';
                total.textContent = source ? source.dataset.totalLabel : subtotal.textContent;
                if (inlineTotal) inlineTotal.textContent = total.textContent;
            }

            form.addEventListener('change', refresh);
            refresh();
        })();
    </script>
@endpush
@endsection
