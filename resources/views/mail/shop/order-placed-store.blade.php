{{-- App\Mail\Shop\OrderPlacedStore: new order notice for the shop, with a link to the panel (never the customer's token link). --}}
@extends('mail.shop._layout')

@section('content')
    <h1 style="margin:0 0 12px 0;font-size:20px;">{{ __('checkout.mail_order_placed_store_subject', ['number' => $order->number]) }}</h1>
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_order_placed_store_intro', ['name' => $order->customer_name, 'total' => $order->total_amount->format()]) }}</p>

    <h2 style="margin:24px 0 8px 0;font-size:16px;">{{ __('checkout.order_customer') }}</h2>
    <p style="margin:0;font-size:14px;">
        {{ $order->customer_name }}<br>
        {{ __('checkout.customer_email') }}: {{ $order->customer_email }}<br>
        {{ __('checkout.customer_phone') }}: {{ $order->customer_phone }}
    </p>

    @if(filled($order->notes))
        <h2 style="margin:24px 0 8px 0;font-size:16px;">{{ __('checkout.order_notes') }}</h2>
        <p style="margin:0;font-size:14px;white-space:pre-line;">{{ $order->notes }}</p>
    @endif

    @include('mail.shop._summary')

    @include('mail.shop._button', ['url' => $panelUrl, 'label' => __('checkout.mail_view_order_panel')])
@endsection
