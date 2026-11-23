{{-- Order lines, totals, delivery and payment method. Expects $order and $pickupAddress (empty when not pickup). --}}
@php
    $methodKey = 'checkout.method_'.$order->payment_method_key;
    $methodLabel = \Illuminate\Support\Facades\Lang::has($methodKey) ? __($methodKey) : $order->payment_method_key;
    $address = $order->shippingAddress;
@endphp
<h2 style="margin:24px 0 8px 0;font-size:16px;">{{ __('checkout.order_summary') }}</h2>
<p style="margin:0 0 12px 0;font-size:13px;color:#6b7280;">
    {{ __('checkout.order_number') }}: <strong style="color:#111;">{{ $order->number }}</strong><br>
    {{ __('checkout.order_date') }}: {{ $order->placed_at?->format('d/m/Y H:i') }}
</p>
<table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e5e7eb;border-radius:6px;font-size:14px;">
    @foreach($order->items as $item)
        <tr>
            <td style="padding:10px 12px;border-bottom:1px solid #e5e7eb;">
                {{ $item->title }}@if($item->variant_name) — {{ $item->variant_name }}@endif
                <br><span style="font-size:12px;color:#6b7280;">{{ $item->quantity }} × {{ $item->unit_price_amount->format() }}</span>
            </td>
            <td align="right" style="padding:10px 12px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">{{ $item->line_total_amount->format() }}</td>
        </tr>
    @endforeach
    <tr>
        <td style="padding:8px 12px;color:#6b7280;">{{ __('checkout.subtotal') }}</td>
        <td align="right" style="padding:8px 12px;white-space:nowrap;">{{ $order->subtotal_amount->format() }}</td>
    </tr>
    <tr>
        <td style="padding:8px 12px;color:#6b7280;">{{ __('checkout.shipping') }}</td>
        <td align="right" style="padding:8px 12px;white-space:nowrap;">{{ $order->shipping_amount->isZero() ? __('checkout.shipping_free') : $order->shipping_amount->format() }}</td>
    </tr>
    <tr>
        <td style="padding:10px 12px;border-top:1px solid #e5e7eb;font-weight:bold;">{{ __('checkout.total') }}</td>
        <td align="right" style="padding:10px 12px;border-top:1px solid #e5e7eb;font-weight:bold;white-space:nowrap;">{{ $order->total_amount->format() }}</td>
    </tr>
</table>

<h2 style="margin:24px 0 8px 0;font-size:16px;">{{ __('checkout.order_delivery') }}</h2>
<p style="margin:0;font-size:14px;">
    @if($order->isPickup())
        {{ __('checkout.delivery_method_pickup') }}
        @if($pickupAddress !== '')
            <br>{{ __('checkout.delivery_pickup_address', ['address' => $pickupAddress]) }}
        @endif
    @else
        {{ __('checkout.delivery_method_delivery') }}@if($order->shipping_zone_name) ({{ $order->shipping_zone_name }})@endif
        @if($address)
            <br>{{ $address->address }}, {{ $address->city }}@if($address->department), {{ $address->department }}@endif
            @if($address->reference)
                <br>{{ __('checkout.address_reference') }}: {{ $address->reference }}
            @endif
        @endif
    @endif
</p>

<h2 style="margin:24px 0 8px 0;font-size:16px;">{{ __('checkout.payment_method') }}</h2>
<p style="margin:0;font-size:14px;">{{ $methodLabel }}</p>
