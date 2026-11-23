{{-- App\Mail\Shop\OrderPlacedCustomer: confirmation, how to pay and the order link. --}}
@extends('mail.shop._layout')

@php
    $method = $order->paymentMethod;
    $pending = $order->status === \App\Models\Order::STATUS_PENDING;
    $manualFields = [];
    if ($pending && $method?->provider === \App\Shop\Payments\ManualPaymentProvider::KEY) {
        foreach (\App\Shop\Payments\ManualPaymentProvider::FIELDS[$method->key] ?? \App\Shop\Payments\ManualPaymentProvider::DEFAULT_FIELDS as $field) {
            $value = $method->setting($field);
            if (is_string($value)) {
                $manualFields[$field] = trim($value);
            }
        }
    }
    $payByCard = $pending && $method !== null && $method->provider !== \App\Shop\Payments\ManualPaymentProvider::KEY;
@endphp

@section('content')
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_greeting', ['name' => $order->customer_name]) }}</p>
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_order_placed_customer_intro', ['site' => $siteName]) }}</p>

    @if($manualFields !== [])
        <h2 style="margin:24px 0 8px 0;font-size:16px;">{{ __('checkout.payment_instructions') }}</h2>
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:14px;">
            @foreach($manualFields as $field => $value)
                <tr>
                    <td style="padding:8px 12px;color:#6b7280;width:40%;vertical-align:top;">{{ __('checkout.'.$field) }}</td>
                    <td style="padding:8px 12px;white-space:pre-line;">{{ $value }}</td>
                </tr>
            @endforeach
            <tr>
                <td style="padding:8px 12px;color:#6b7280;">{{ __('checkout.total') }}</td>
                <td style="padding:8px 12px;font-weight:bold;">{{ $order->total_amount->format() }}</td>
            </tr>
        </table>
        @if($method->key === 'bank_transfer')
            <p style="margin:8px 0 0 0;font-size:14px;">{{ __('checkout.transfer_reference', ['number' => $order->number]) }}</p>
        @endif
    @endif

    @if($payByCard && $orderUrl)
        <p style="margin:24px 0 0 0;">{{ __('checkout.payment_pending') }}: {{ $order->total_amount->format() }}</p>
        @include('mail.shop._button', ['url' => $orderUrl, 'label' => __('checkout.pay_with_card')])
    @endif

    @include('mail.shop._summary')

    @if($orderUrl && ! $payByCard)
        @include('mail.shop._button', ['url' => $orderUrl, 'label' => __('checkout.mail_view_order')])
    @endif
@endsection

@section('footer')
    {{ __('checkout.mail_footer', ['site' => $siteName]) }}
@endsection
