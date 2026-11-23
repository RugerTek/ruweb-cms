{{-- App\Mail\Shop\OrderPaidCustomer: payment confirmed. Only date, amount and authorization code of the payment (PLAN.md E3.1). --}}
@extends('mail.shop._layout')

@section('content')
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_greeting', ['name' => $order->customer_name]) }}</p>
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_order_paid_customer_intro') }}</p>

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;font-size:14px;">
        <tr>
            <td style="padding:8px 12px;color:#6b7280;width:40%;">{{ __('checkout.order_number') }}</td>
            <td style="padding:8px 12px;">{{ $order->number }}</td>
        </tr>
        <tr>
            <td style="padding:8px 12px;color:#6b7280;">{{ __('checkout.order_date') }}</td>
            <td style="padding:8px 12px;">{{ $paidAt->format('d/m/Y H:i') }}</td>
        </tr>
        <tr>
            <td style="padding:8px 12px;color:#6b7280;">{{ __('checkout.payment_amount') }}</td>
            <td style="padding:8px 12px;font-weight:bold;">{{ $paidAmount->format() }}</td>
        </tr>
        @if(filled($authorizationNumber))
            <tr>
                <td style="padding:8px 12px;color:#6b7280;">{{ __('checkout.payment_authorization') }}</td>
                <td style="padding:8px 12px;">{{ $authorizationNumber }}</td>
            </tr>
        @endif
    </table>

    @include('mail.shop._summary')

    @if($orderUrl)
        @include('mail.shop._button', ['url' => $orderUrl, 'label' => __('checkout.mail_view_order')])
    @endif
@endsection

@section('footer')
    {{ __('checkout.mail_footer', ['site' => $siteName]) }}
@endsection
