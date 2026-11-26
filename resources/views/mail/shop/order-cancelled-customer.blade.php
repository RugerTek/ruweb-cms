{{-- App\Mail\Shop\OrderCancelledCustomer: order cancelled, with the reason when it was not paid in time. --}}
@extends('mail.shop._layout')

@section('content')
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_greeting', ['name' => $order->customer_name]) }}</p>
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_order_cancelled_customer_intro') }}</p>
    @if($unpaid)
        <p style="margin:0 0 12px 0;">{{ __('checkout.mail_order_cancelled_unpaid') }}</p>
    @endif

    @include('mail.shop._summary')

    @if($orderUrl)
        @include('mail.shop._button', ['url' => $orderUrl, 'label' => __('checkout.mail_view_order')])
    @endif
@endsection

@section('footer')
    {{ __('checkout.mail_footer', ['site' => $siteName]) }}
@endsection
