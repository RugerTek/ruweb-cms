{{-- App\Mail\Shop\LatePaymentStore: the provider approved a charge for an attempt already failed/expired, or for another amount. --}}
@extends('mail.shop._layout')

@section('content')
    <h1 style="margin:0 0 12px 0;font-size:20px;">{{ __('checkout.mail_late_payment_subject', ['number' => $order->number]) }}</h1>
    @if($reason === 'amount_mismatch')
        <p style="margin:0 0 12px 0;" data-stray-reason="amount_mismatch">{{ __('checkout.mail_mismatch_payment_intro', ['number' => $order->number, 'expected' => $amount, 'reported' => $reported]) }}</p>
    @else
        <p style="margin:0 0 12px 0;">{{ __('checkout.mail_late_payment_intro', ['number' => $order->number, 'amount' => $amount, 'status' => $status]) }}</p>
    @endif
    @if($reversed)
        <p style="margin:0 0 12px 0;" data-late-payment="reversed">{{ __('checkout.mail_late_payment_reversed') }}</p>
    @else
        <p style="margin:0 0 12px 0;" data-late-payment="review">{{ __('checkout.mail_late_payment_review', ['code' => $code]) }}</p>
    @endif

    @include('mail.shop._button', ['url' => $panelUrl, 'label' => __('checkout.mail_view_order_panel')])
@endsection
