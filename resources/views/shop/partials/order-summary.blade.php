{{--
    Order summary box shared by the checkout (lines from CartService::summary()) and the order page (frozen order
    items). Each line: title, variant_name, quantity, unit_price (Money), line_total (Money).
    $shipping: Money, or null (free, or "—" with $shippingPending while the delivery is not chosen yet).
    $total: Money, or null for the subtotal. The checkout rewrites the shipping and total rows from the chosen
    delivery option (data-summary-shipping / data-summary-total).
--}}
@php
    $shipping ??= null;
    $total ??= null;
    $shippingPending ??= false;
@endphp
<section class="rounded-xl border border-gray-200 bg-gray-50 p-5" aria-labelledby="order-summary-title" data-order-summary>
    <h2 id="order-summary-title" class="text-lg font-semibold text-gray-900">{{ __('checkout.order_summary') }}</h2>
    <ul class="mt-4 divide-y divide-gray-200">
        @foreach($lines as $line)
            <li class="flex items-start justify-between gap-4 py-3 text-sm" data-summary-line>
                <div class="min-w-0">
                    <p class="font-medium text-gray-900">{{ $line['title'] }}</p>
                    @if(filled($line['variant_name'] ?? null))
                        <p class="text-gray-500">{{ $line['variant_name'] }}</p>
                    @endif
                    <p class="text-gray-500">{{ $line['quantity'] }} × {{ $line['unit_price']?->format() }}</p>
                </div>
                <p class="shrink-0 font-medium text-gray-900">{{ $line['line_total']?->format() }}</p>
            </li>
        @endforeach
    </ul>
    <dl class="mt-4 space-y-2 border-t border-gray-200 pt-4 text-sm">
        <div class="flex justify-between">
            <dt class="text-gray-600">{{ __('checkout.subtotal') }}</dt>
            <dd class="text-gray-900" data-summary-subtotal>{{ $subtotal->format() }}</dd>
        </div>
        <div class="flex justify-between">
            <dt class="text-gray-600">{{ __('checkout.shipping') }}</dt>
            <dd class="text-gray-900" data-summary-shipping>
                @if($shipping === null)
                    {{ $shippingPending ? '—' : __('checkout.shipping_free') }}
                @else
                    {{ $shipping->isZero() ? __('checkout.shipping_free') : $shipping->format() }}
                @endif
            </dd>
        </div>
        <div class="flex justify-between border-t border-gray-200 pt-2 text-base font-semibold">
            <dt class="text-gray-900">{{ __('checkout.total') }}</dt>
            <dd class="text-gray-900" data-summary-total>{{ ($total ?? $subtotal)->format() }}</dd>
        </div>
    </dl>
</section>
