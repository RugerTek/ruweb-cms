{{-- App\Mail\Shop\PaymentAmountMismatchStore: a verified payment notification reported another amount; the attempt failed. --}}
@extends('mail.shop._layout')

@section('content')
    <h1 style="margin:0 0 12px 0;font-size:20px;">{{ __('checkout.mail_amount_mismatch_subject', ['number' => $order->number]) }}</h1>
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_amount_mismatch_intro', ['number' => $order->number, 'expected' => $expected, 'reported' => $reported]) }}</p>
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_amount_mismatch_action') }}</p>

    @include('mail.shop._button', ['url' => $panelUrl, 'label' => __('checkout.mail_view_order_panel')])
@endsection
