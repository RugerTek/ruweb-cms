{{-- App\Mail\Shop\StockShortfallStore: a paid order could not take all its stock; restock or refund from the panel. --}}
@extends('mail.shop._layout')

@section('content')
    <h1 style="margin:0 0 12px 0;font-size:20px;">{{ __('checkout.mail_stock_shortfall_subject', ['number' => $order->number]) }}</h1>
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_stock_shortfall_intro', ['number' => $order->number]) }}</p>
    <ul style="margin:0 0 12px 0;padding-left:20px;font-size:14px;">
        @foreach($lines as $line)
            <li>{{ __('checkout.mail_stock_shortfall_line', ['title' => $line['title'] ?? '', 'requested' => $line['requested'] ?? 0, 'available' => $line['available'] ?? 0]) }}</li>
        @endforeach
    </ul>
    <p style="margin:0 0 12px 0;">{{ __('checkout.mail_stock_shortfall_action') }}</p>

    @include('mail.shop._button', ['url' => $panelUrl, 'label' => __('checkout.mail_view_order_panel')])
@endsection
