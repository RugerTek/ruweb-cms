@extends('layouts.public')

@use('App\Models\Order')
@use('App\Models\Payment')
@use('App\Services\Shop\CheckoutService')

@push('head')
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    @if($paymentState === 'confirming')
        <meta http-equiv="refresh" content="{{ $refreshSeconds }}">
    @endif
@endpush

@section('content')
@php
    $lines = $order->items->map(fn ($item) => [
        'title' => $item->title,
        'variant_name' => $item->variant_name,
        'quantity' => $item->quantity,
        'unit_price' => $item->unit_price_amount,
        'line_total' => $item->line_total_amount,
    ]);
    $dateFormat = 'd/m/Y H:i';
    $timezone = config('app.timezone');
    $online = $payment !== null && $payment->provider !== \App\Shop\Payments\ManualPaymentProvider::KEY;
    $address = $order->shippingAddress;
    $pickupAddress = $order->isPickup() ? app(CheckoutService::class)->pickupAddress() : null;
    $statusTone = match ($order->status) {
        Order::STATUS_PAID, Order::STATUS_FULFILLED => 'bg-sky-50 text-sky-700',
        Order::STATUS_CANCELLED, Order::STATUS_REFUNDED => 'bg-gray-100 text-gray-600',
        default => 'bg-amber-50 text-amber-800',
    };
@endphp
<section class="py-14 sm:py-16" data-shop-order>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        @if($justPlaced)
            <div class="mb-8 rounded-xl border border-sky-200 bg-sky-50 p-5" role="status" data-order-placed>
                <p class="text-lg font-semibold text-sky-900">{{ __('checkout.order_thanks') }}</p>
                <p class="mt-1 text-sm text-sky-800">{{ __('checkout.order_link_hint') }}</p>
            </div>
        @endif

        @if($errors->any())
            <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="text-3xl sm:text-4xl font-semibold tracking-tight text-gray-900">{{ __('checkout.order_title', ['number' => $order->number]) }}</h1>
            <span class="inline-flex w-fit rounded-full px-3 py-1 text-sm font-medium {{ $statusTone }}" data-order-status="{{ $order->status }}">
                <span class="sr-only">{{ __('checkout.order_status') }}:</span>{{ __('checkout.status_'.$order->status) }}
            </span>
        </div>
        <p class="mt-2 text-sm text-gray-500">{{ __('checkout.order_date') }}: {{ $order->placed_at->timezone($timezone)->format($dateFormat) }}</p>

        @if($order->status === Order::STATUS_CANCELLED)
            <p class="mt-6 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">{{ __('checkout.order_cancelled_notice') }}</p>
        @elseif($order->status === Order::STATUS_REFUNDED)
            <p class="mt-6 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">{{ __('checkout.order_refunded_notice') }}</p>
        @elseif($order->status === Order::STATUS_FULFILLED)
            <p class="mt-6 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">{{ __('checkout.order_fulfilled_notice') }}</p>
        @endif

        <div class="mt-8 grid grid-cols-1 gap-10 lg:grid-cols-3">
            <div class="space-y-8 lg:col-span-2">
                {{-- Payment --}}
                <section class="rounded-xl border border-gray-200 p-5" aria-labelledby="order-payment-title" data-order-payment="{{ $paymentState }}">
                    <h2 id="order-payment-title" class="text-lg font-semibold text-gray-900">{{ __('checkout.payment') }}</h2>
                    <p class="mt-1 text-sm text-gray-600">{{ CheckoutService::methodLabel($order->paymentMethod ?? $order->payment_method_key) }}</p>

                    @if($paymentState === 'collect')
                        @if($paymentStartFailed)
                            <p class="mt-4 text-sm text-red-600" data-payment-start-failed>{{ __('checkout.payment_failed') }}</p>
                            <a href="{{ $orderUrl }}" class="mt-3 inline-flex rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('checkout.retry_payment') }}</a>
                        @elseif($paymentStart?->isIframe())
                            @include('shop.partials.bancard-iframe', ['paymentStart' => $paymentStart])
                        @elseif($paymentStart)
                            <p class="mt-4 text-sm font-medium text-amber-800">{{ __('checkout.payment_pending') }}</p>
                            <h3 class="mt-4 text-sm font-semibold text-gray-900">{{ __('checkout.payment_instructions') }}</h3>
                            <dl class="mt-2 grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-3" data-payment-instructions>
                                @foreach($paymentStart->instructions as $field => $value)
                                    <dt class="text-gray-500">{{ \Illuminate\Support\Facades\Lang::has('checkout.'.$field) ? __('checkout.'.$field) : $field }}</dt>
                                    <dd class="whitespace-pre-line text-gray-900 sm:col-span-2">{{ $value }}</dd>
                                @endforeach
                            </dl>
                            @if($order->payment_method_key === 'bank_transfer')
                                <p class="mt-4 text-sm text-gray-700">{{ __('checkout.transfer_reference', ['number' => $order->number]) }}</p>
                            @endif
                        @endif
                    @elseif($paymentState === 'confirming')
                        <p class="mt-4 text-sm text-gray-700" role="status" data-payment-confirming>{{ __('checkout.payment_confirming') }}</p>
                    @elseif(in_array($paymentState, [Payment::STATUS_FAILED, Payment::STATUS_EXPIRED], true))
                        <p class="mt-4 text-sm font-medium text-red-600">{{ $paymentState === Payment::STATUS_EXPIRED ? __('checkout.payment_expired') : __('checkout.payment_failed') }}</p>
                        @if($payment->response_description && $paymentState === Payment::STATUS_FAILED)
                            <p class="mt-1 text-sm text-gray-600">{{ __('checkout.payment_description') }}: {{ $payment->response_description }}</p>
                        @endif
                        @if($online && $order->status === Order::STATUS_PENDING)
                            <p class="mt-1 text-sm text-gray-600">{{ __('checkout.payment_failed_retry') }}</p>
                            <form method="POST" action="{{ $payUrl }}" class="mt-3" data-payment-retry>
                                @csrf
                                <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">{{ __('checkout.retry_payment') }}</button>
                            </form>
                        @endif
                    @elseif($paymentState === Payment::STATUS_CONFIRMED)
                        <p class="mt-4 text-sm font-medium text-sky-700">{{ __('checkout.payment_confirmed') }}</p>
                        <dl class="mt-3 grid grid-cols-1 gap-x-4 gap-y-2 text-sm sm:grid-cols-3" data-payment-receipt>
                            <dt class="text-gray-500">{{ __('checkout.order_date') }}</dt>
                            <dd class="text-gray-900 sm:col-span-2">{{ ($payment->confirmed_at ?? $payment->updated_at)->timezone($timezone)->format($dateFormat) }}</dd>
                            <dt class="text-gray-500">{{ __('checkout.order_number') }}</dt>
                            <dd class="text-gray-900 sm:col-span-2">{{ $order->number }}</dd>
                            <dt class="text-gray-500">{{ __('checkout.payment_amount') }}</dt>
                            <dd class="text-gray-900 sm:col-span-2">{{ $payment->amount->format() }}</dd>
                            @if($payment->response_description)
                                <dt class="text-gray-500">{{ __('checkout.payment_description') }}</dt>
                                <dd class="text-gray-900 sm:col-span-2">{{ $payment->response_description }}</dd>
                            @endif
                            @if($payment->authorization_number)
                                <dt class="text-gray-500">{{ __('checkout.payment_authorization') }}</dt>
                                <dd class="text-gray-900 sm:col-span-2">{{ $payment->authorization_number }}</dd>
                            @endif
                        </dl>
                    @elseif($paymentState === Payment::STATUS_REFUNDED)
                        <p class="mt-4 text-sm text-gray-700">{{ __('checkout.payment_status_refunded') }}</p>
                    @endif
                </section>

                {{-- Customer and delivery --}}
                <section class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('checkout.order_customer') }}</h2>
                        <p class="mt-2 text-sm text-gray-700">{{ $order->customer_name }}</p>
                        <p class="text-sm text-gray-700">{{ $order->customer_email }}</p>
                        <p class="text-sm text-gray-700">{{ $order->customer_phone }}</p>
                    </div>
                    <div data-order-delivery="{{ $order->delivery_method }}">
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('checkout.order_delivery') }}</h2>
                        <p class="mt-2 text-sm text-gray-700">{{ __('checkout.delivery_method_'.$order->delivery_method) }}@if($order->shipping_zone_name) · {{ $order->shipping_zone_name }}@endif</p>
                        @if($address)
                            <p class="text-sm text-gray-700">{{ $address->address }}, {{ $address->city }}@if($address->department), {{ $address->department }}@endif</p>
                            @if($address->reference)
                                <p class="text-sm text-gray-500">{{ $address->reference }}</p>
                            @endif
                        @elseif($pickupAddress)
                            <p class="text-sm text-gray-700">{{ $pickupAddress }}</p>
                        @endif
                    </div>
                </section>

                @if($order->notes)
                    <section>
                        <h2 class="text-sm font-semibold text-gray-900">{{ __('checkout.order_notes') }}</h2>
                        <p class="mt-2 whitespace-pre-line text-sm text-gray-700">{{ $order->notes }}</p>
                    </section>
                @endif
            </div>

            <div class="h-fit">
                @include('shop.partials.order-summary', [
                    'lines' => $lines,
                    'subtotal' => $order->subtotal_amount,
                    'shipping' => $order->shipping_amount,
                    'total' => $order->total_amount,
                ])
            </div>
        </div>
    </div>
</section>
@endsection
