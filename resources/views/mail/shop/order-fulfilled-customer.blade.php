{{-- App\Mail\Shop\OrderFulfilledCustomer: order marked delivered. Home delivery: on its way or delivered; pickup: ready or picked up, with the address. --}}
@extends('mail.shop._layout')

@section('content')
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_greeting', ['name' => $order->customer_name]) }}</p>
    @if($order->isPickup())
        <p style="margin:0 0 12px 0;">{{ __('checkout.mail_order_fulfilled_pickup_intro') }}</p>
        @if($pickupAddress !== '')
            <p style="margin:0 0 12px 0;"><strong>{{ __('checkout.mail_order_fulfilled_pickup_address') }}</strong><br><span style="white-space:pre-line;">{{ $pickupAddress }}</span></p>
        @endif
    @else
        <p style="margin:0 0 12px 0;">{{ __('checkout.mail_order_fulfilled_delivery_intro') }}</p>
    @endif
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_order_fulfilled_help') }}</p>

    @include('mail.shop._summary')

    @if($orderUrl)
        @include('mail.shop._button', ['url' => $orderUrl, 'label' => __('checkout.mail_view_order')])
    @endif
@endsection

@section('footer')
    {{ __('checkout.mail_footer', ['site' => $siteName]) }}
@endsection
