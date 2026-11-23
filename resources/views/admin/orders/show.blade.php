@extends('admin.layouts.app')

@section('title', __('checkout.order_title', ['number' => $order->number]))

@section('content')
@php
    $money = fn ($amount) => \App\Helpers\MoneyHelper::format($amount);
    $statusBadge = [
        'pending' => 'bg-yellow-500/10 text-yellow-400',
        'paid' => 'bg-sky-500/10 text-sky-400',
        'fulfilled' => 'bg-emerald-500/10 text-emerald-400',
        'cancelled' => 'bg-white/[0.06] text-gray-400',
        'refunded' => 'bg-purple-500/10 text-purple-300',
    ];
    $paymentBadge = [
        'pending' => 'bg-yellow-500/10 text-yellow-400',
        'confirmed' => 'bg-sky-500/10 text-sky-400',
        'failed' => 'bg-red-500/10 text-red-400',
        'expired' => 'bg-white/[0.06] text-gray-400',
        'refunded' => 'bg-purple-500/10 text-purple-300',
    ];
    $card = 'bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5';
    $heading = 'text-sm font-semibold text-white mb-4';
    $primaryButton = 'w-full inline-flex justify-center items-center px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors';
    $secondaryButton = 'w-full inline-flex justify-center items-center px-3 py-2 bg-gray-800 border border-white/10 text-gray-300 text-sm rounded-md hover:bg-gray-700 transition-colors';
    $dangerButton = 'w-full inline-flex justify-center items-center px-3 py-2 bg-red-500/10 hover:bg-red-500/20 text-red-400 text-sm font-medium rounded-md transition-colors';
    $smallButton = 'inline-flex items-center px-2.5 py-1.5 text-xs font-medium rounded-md transition-colors';
    $address = $order->shippingAddress;
@endphp

<div class="mb-6 flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">
            <a href="{{ route('admin.orders.index') }}" class="hover:text-gray-300 transition-colors">{{ __('checkout.orders_title') }}</a>
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-gray-400">{{ __('checkout.order_detail') }}</span>
        </div>
        <h1 class="text-lg font-semibold text-white flex flex-wrap items-center gap-3">
            <span class="font-mono">{{ $order->number }}</span>
            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusBadge[$order->status] ?? '' }}">{{ __('checkout.status_'.$order->status) }}</span>
        </h1>
        <p class="text-sm text-gray-500 mt-1">{{ $order->placed_at?->format('d/m/Y H:i') }} · {{ $methodLabels[$order->payment_method_key] ?? $order->payment_method_key }}</p>
    </div>
</div>

@if($errors->any())
    <div class="mb-4 p-3.5 bg-red-500/10 border border-red-500/20 text-red-300 rounded-md text-sm" role="alert">
        @foreach($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

@if(! empty($order->stock_shortfall))
    <div class="mb-4 p-3.5 bg-orange-500/10 border border-orange-500/20 text-orange-300 rounded-md text-sm">
        <p class="font-medium">{{ __('checkout.stock_shortfall_alert') }}</p>
        <ul class="mt-1 list-disc list-inside">
            @foreach($order->stock_shortfall as $line)
                <li>{{ __('checkout.mail_stock_shortfall_line', ['title' => $line['title'] ?? '', 'requested' => $line['requested'] ?? 0, 'available' => $line['available'] ?? 0]) }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if($latePaymentIds !== [])
    <div class="mb-4 p-3.5 bg-red-500/10 border border-red-500/20 text-red-300 rounded-md text-sm" data-late-payment>
        <p class="font-medium">{{ __('checkout.late_payment_badge') }}</p>
        <p class="mt-1">{{ __('checkout.late_payment_alert') }}</p>
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        {{-- Items --}}
        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">{{ __('checkout.order_items') }}</h2>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/[0.06]">
                            <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider pb-2 pr-4">{{ __('checkout.order_items') }}</th>
                            <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider pb-2 px-2">{{ __('checkout.quantity') }}</th>
                            <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider pb-2 px-2 hidden sm:table-cell">{{ __('checkout.unit_price') }}</th>
                            <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider pb-2 pl-2">{{ __('checkout.line_total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.04]">
                        @foreach($order->items as $item)
                            <tr>
                                <td class="py-2.5 pr-4">
                                    <p class="text-sm text-gray-200">{{ $item->title }}</p>
                                    @if($item->variant_name || $item->sku)
                                        <p class="text-xs text-gray-600 mt-0.5">{{ collect([$item->variant_name, $item->sku])->filter()->implode(' · ') }}</p>
                                    @endif
                                </td>
                                <td class="py-2.5 px-2 text-right text-sm text-gray-400">{{ $item->quantity }}</td>
                                <td class="py-2.5 px-2 text-right text-sm text-gray-400 whitespace-nowrap hidden sm:table-cell">{{ $money($item->unit_price_amount) }}</td>
                                <td class="py-2.5 pl-2 text-right text-sm text-gray-200 whitespace-nowrap">{{ $money($item->line_total_amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <dl class="mt-4 pt-4 border-t border-white/[0.06] space-y-1.5 text-sm">
                <div class="flex justify-between text-gray-400"><dt>{{ __('checkout.subtotal') }}</dt><dd class="whitespace-nowrap">{{ $money($order->subtotal_amount) }}</dd></div>
                <div class="flex justify-between text-gray-400">
                    <dt>{{ __('checkout.shipping') }}@if($order->shipping_zone_name) · {{ $order->shipping_zone_name }}@endif</dt>
                    <dd class="whitespace-nowrap">{{ $order->shipping_amount->amount > 0 ? $money($order->shipping_amount) : __('checkout.shipping_free') }}</dd>
                </div>
                <div class="flex justify-between text-white font-semibold"><dt>{{ __('checkout.total') }}</dt><dd class="whitespace-nowrap">{{ $money($order->total_amount) }}</dd></div>
            </dl>
            @if($order->stock_applied_at === null && in_array($order->status, ['pending', 'cancelled'], true))
                <p class="mt-3 text-xs text-gray-600">{{ __('checkout.stock_not_applied') }}</p>
            @endif
        </section>

        {{-- Payment attempts and what the provider reported (summarised: never tokens, keys or raw payloads) --}}
        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">{{ __('checkout.payment_attempts') }}</h2>
            <div class="space-y-4">
                @forelse($order->payments->sortByDesc('id') as $payment)
                    @php
                        $online = $payment->provider !== \App\Shop\Payments\ManualPaymentProvider::KEY;
                        $late = in_array($payment->id, $latePaymentIds, true);
                    @endphp
                    <div class="rounded-lg ring-1 ring-white/[0.06] p-4" data-payment="{{ $payment->id }}">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm text-gray-200">{{ $methodLabels[$payment->method_key] ?? $payment->method_key }}</span>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $paymentBadge[$payment->status] ?? '' }}">{{ __('checkout.payment_status_'.$payment->status) }}</span>
                                @if($late)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/10 text-red-400">{{ __('checkout.late_payment_badge') }}</span>
                                @endif
                                @if($payment->environment)
                                    <span class="text-xs text-gray-600">{{ __('checkout.environment_'.$payment->environment) }}</span>
                                @endif
                            </div>
                            <span class="text-sm text-gray-300 whitespace-nowrap">{{ $money($payment->amount) }}</span>
                        </div>
                        <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-xs text-gray-500">
                            <div>{{ __('checkout.order_date') }}: {{ $payment->created_at?->format('d/m/Y H:i') }}</div>
                            @if($payment->shop_process_id)
                                <div>{{ __('checkout.payment_reference') }}: {{ $payment->shop_process_id }}</div>
                            @endif
                            @if($payment->authorization_number)
                                <div>{{ __('checkout.payment_authorization') }}: {{ $payment->authorization_number }}</div>
                            @endif
                            @if($payment->response_description)
                                <div class="sm:col-span-2">{{ __('checkout.payment_description') }}: {{ $payment->response_description }}</div>
                            @endif
                        </dl>

                        @php
                            $actions = [];
                            if ($payment->isPending() && ! $online) {
                                $actions[] = 'confirm';
                                $actions[] = 'fail';
                            } elseif ($payment->isPending() && filled($payment->process_id)) {
                                $actions[] = 'verify';
                            }
                            if ($payment->status === 'confirmed') {
                                if ($online) {
                                    $actions[] = 'refund';
                                }
                                $actions[] = 'refund-manual';
                            }
                        @endphp
                        @if(auth()->user()->isAdmin() && $actions !== [])
                            <div class="mt-3 flex flex-wrap gap-2">
                                @if(in_array('confirm', $actions, true))
                                    @can('confirmPayment', $order)
                                        <form method="POST" action="{{ route('admin.payments.confirm', $payment) }}" data-confirm="{{ __('checkout.confirm_payment_confirm', ['amount' => $money($payment->amount)]) }}" data-confirm-label="{{ __('checkout.confirm_payment') }}">
                                            @csrf
                                            <button type="submit" class="{{ $smallButton }} bg-sky-600 hover:bg-sky-700 text-white">{{ __('checkout.confirm_payment') }}</button>
                                        </form>
                                    @endcan
                                @endif
                                @if(in_array('fail', $actions, true))
                                    @can('failPayment', $order)
                                        <form method="POST" action="{{ route('admin.payments.fail', $payment) }}" data-confirm="{{ __('checkout.fail_payment_confirm') }}" data-confirm-label="{{ __('checkout.fail_payment') }}">
                                            @csrf
                                            <button type="submit" class="{{ $smallButton }} bg-gray-800 border border-white/10 text-gray-300 hover:bg-gray-700">{{ __('checkout.fail_payment') }}</button>
                                        </form>
                                    @endcan
                                @endif
                                @if(in_array('verify', $actions, true))
                                    @can('confirmPayment', $order)
                                        <form method="POST" action="{{ route('admin.payments.confirm', $payment) }}">
                                            @csrf
                                            <button type="submit" class="{{ $smallButton }} bg-sky-600 hover:bg-sky-700 text-white" title="{{ __('checkout.verify_payment_help') }}">{{ __('checkout.verify_payment') }}</button>
                                        </form>
                                        <p class="w-full text-xs text-gray-600">{{ __('checkout.verify_payment_help') }}</p>
                                    @endcan
                                @endif
                                @if(in_array('refund', $actions, true))
                                    @can('refund', $order)
                                        <form method="POST" action="{{ route('admin.payments.refund', $payment) }}" data-confirm="{{ __('checkout.refund_payment_confirm', ['amount' => $money($payment->amount)]) }}" data-confirm-label="{{ __('checkout.refund_payment') }}">
                                            @csrf
                                            <button type="submit" class="{{ $smallButton }} bg-red-500/10 hover:bg-red-500/20 text-red-400">{{ __('checkout.refund_payment') }}</button>
                                        </form>
                                    @endcan
                                @endif
                                @if(in_array('refund-manual', $actions, true))
                                    @can('refund', $order)
                                        <form method="POST" action="{{ route('admin.payments.refund-manual', $payment) }}" data-confirm="{{ __('checkout.refund_manual_confirm', ['amount' => $money($payment->amount)]) }}" data-confirm-label="{{ __('checkout.refund_manual') }}">
                                            @csrf
                                            <button type="submit" class="{{ $smallButton }} bg-gray-800 border border-white/10 text-gray-300 hover:bg-gray-700">{{ __('checkout.refund_manual') }}</button>
                                        </form>
                                    @endcan
                                @endif
                            </div>
                        @endif

                        @if($late && auth()->user()->isAdmin())
                            @can('refund', $order)
                                <form method="POST" action="{{ route('admin.payments.resolve', $payment) }}" class="mt-3 space-y-2" data-late-payment-resolve data-confirm="{{ __('checkout.late_payment_resolve_confirm') }}" data-confirm-label="{{ __('checkout.late_payment_resolve') }}">
                                    @csrf
                                    <label for="resolve-note-{{ $payment->id }}" class="block text-xs text-gray-400">{{ __('checkout.late_payment_resolve_note') }}</label>
                                    <textarea id="resolve-note-{{ $payment->id }}" name="note" rows="2" required maxlength="{{ \App\Services\Shop\StrayChargeOperations::NOTE_MAX }}"
                                              class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">{{ old('note') }}</textarea>
                                    @error('note')
                                        <p class="text-xs text-red-400">{{ $message }}</p>
                                    @enderror
                                    <button type="submit" class="{{ $smallButton }} bg-gray-800 border border-white/10 text-gray-300 hover:bg-gray-700">{{ __('checkout.late_payment_resolve') }}</button>
                                </form>
                            @endcan
                        @endif

                        @if($payment->events->isNotEmpty())
                            <details class="mt-3">
                                <summary class="text-xs text-gray-500 cursor-pointer hover:text-gray-300">{{ __('checkout.payment_events') }} ({{ $payment->events->count() }})</summary>
                                <ul class="mt-2 space-y-1.5">
                                    @foreach($payment->events->sortByDesc('id') as $event)
                                        @php
                                            $payload = $event->payload_json ?? [];
                                            $approved = \App\Http\Controllers\Admin\OrderController::isApproval($payload);
                                            $operation = is_array($payload['operation'] ?? null) ? $payload['operation'] : [];
                                            $typeKey = 'checkout.event_type_'.$event->type;
                                        @endphp
                                        <li class="text-xs text-gray-500 flex flex-wrap gap-x-2">
                                            <span class="text-gray-400">{{ $event->created_at?->format('d/m/Y H:i:s') }}</span>
                                            <span>{{ \Illuminate\Support\Facades\Lang::has($typeKey) ? __($typeKey) : $event->type }}</span>
                                            @if($event->token_valid !== null)
                                                <span class="{{ $event->token_valid ? 'text-emerald-400' : 'text-red-400' }}">{{ $event->token_valid ? __('checkout.event_token_valid') : __('checkout.event_token_invalid') }}</span>
                                            @endif
                                            @if($approved !== null)
                                                <span class="{{ $approved ? 'text-sky-400' : 'text-red-400' }}">{{ $approved ? __('checkout.event_approved') : __('checkout.event_rejected') }}</span>
                                            @endif
                                            @if($event->type === \App\Models\PaymentEvent::TYPE_API_CALL && is_string($payload['operation'] ?? null))
                                                <span>{{ $payload['operation'] }}@if(is_string($payload['key'] ?? null)) · {{ $payload['key'] }}@endif</span>
                                            @elseif(is_string($payload['code'] ?? null))
                                                <span>{{ $payload['code'] }}</span>
                                            @endif
                                            @if($event->type === \App\Shop\Payments\Bancard\BancardProvider::EVENT_LATE_PAYMENT_RESOLVED && is_string($payload['note'] ?? null))
                                                <span class="text-gray-300" data-resolve-note>{{ $payload['note'] }}@if(is_string($payload['user'] ?? null)) · {{ $payload['user'] }}@endif</span>
                                            @endif
                                            @if(is_string($operation['response_description'] ?? null))
                                                <span>{{ $operation['response_description'] }}</span>
                                            @endif
                                            @if(in_array($payload['status_before'] ?? null, ['failed', 'expired', 'confirmed', 'refunded'], true))
                                                <span>({{ __('checkout.event_status_before', ['status' => __('checkout.payment_status_'.$payload['status_before'])]) }})</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </details>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-gray-600">—</p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="space-y-6">
        {{-- Actions --}}
        @php
            $canFulfill = $order->canTransitionTo('fulfilled') && auth()->user()->can('fulfill', $order);
            $canCancel = $order->canTransitionTo('cancelled') && auth()->user()->can('cancel', $order);
            $canResend = auth()->user()->can('resend', $order);
        @endphp
        @if($canFulfill || $canCancel || $canResend)
            <section class="{{ $card }} space-y-2">
                <h2 class="{{ $heading }}">{{ __('admin.actions') }}</h2>
                @if($canFulfill)
                    <form method="POST" action="{{ route('admin.orders.transition', $order) }}">
                        @csrf
                        <input type="hidden" name="status" value="fulfilled">
                        <button type="submit" class="{{ $primaryButton }}">{{ __('checkout.mark_fulfilled') }}</button>
                    </form>
                @endif
                @if($canResend)
                    <form method="POST" action="{{ route('admin.orders.resend', $order) }}">
                        @csrf
                        <button type="submit" class="{{ $secondaryButton }}">{{ __('checkout.resend_email') }}</button>
                    </form>
                @endif
                @if($canCancel)
                    <form method="POST" action="{{ route('admin.orders.transition', $order) }}" data-confirm="{{ __('checkout.cancel_order_confirm', ['number' => $order->number]) }}" data-confirm-label="{{ __('checkout.cancel_order') }}">
                        @csrf
                        <input type="hidden" name="status" value="cancelled">
                        <button type="submit" class="{{ $dangerButton }}">{{ __('checkout.cancel_order') }}</button>
                    </form>
                @endif
            </section>
        @endif

        {{-- Customer --}}
        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">{{ __('checkout.order_customer') }}</h2>
            <dl class="space-y-2 text-sm">
                <div><dt class="text-xs text-gray-500">{{ __('checkout.customer_name') }}</dt><dd class="text-gray-200">{{ $order->customer_name }}</dd></div>
                <div><dt class="text-xs text-gray-500">{{ __('checkout.customer_email') }}</dt><dd><a href="mailto:{{ $order->customer_email }}" class="text-sky-400 hover:text-sky-300 break-all">{{ $order->customer_email }}</a></dd></div>
                <div><dt class="text-xs text-gray-500">{{ __('checkout.customer_phone') }}</dt><dd class="text-gray-200">{{ $order->customer_phone }}</dd></div>
                @if(filled($order->notes))
                    <div><dt class="text-xs text-gray-500">{{ __('checkout.notes') }}</dt><dd class="text-gray-300 whitespace-pre-line">{{ $order->notes }}</dd></div>
                @endif
            </dl>
        </section>

        {{-- Delivery --}}
        <section class="{{ $card }}">
            <h2 class="{{ $heading }}">{{ __('checkout.order_delivery') }}</h2>
            <p class="text-sm text-gray-200">{{ __('checkout.delivery_method_'.$order->delivery_method) }}</p>
            @if(! $order->isPickup())
                @if($order->shipping_zone_name)
                    <p class="text-xs text-gray-500 mt-1">{{ __('checkout.shipping_zone') }}: {{ $order->shipping_zone_name }}</p>
                @endif
                @if($address)
                    <dl class="mt-3 space-y-2 text-sm">
                        <div><dt class="text-xs text-gray-500">{{ __('checkout.address_line') }}</dt><dd class="text-gray-200">{{ $address->address }}</dd></div>
                        <div><dt class="text-xs text-gray-500">{{ __('checkout.address_city') }}</dt><dd class="text-gray-200">{{ collect([$address->city, $address->department])->filter()->implode(', ') }}</dd></div>
                        @if(filled($address->reference))
                            <div><dt class="text-xs text-gray-500">{{ __('checkout.address_reference') }}</dt><dd class="text-gray-300">{{ $address->reference }}</dd></div>
                        @endif
                    </dl>
                @endif
            @endif
            @foreach(['paid_at' => 'status_paid', 'fulfilled_at' => 'status_fulfilled', 'cancelled_at' => 'status_cancelled', 'refunded_at' => 'status_refunded'] as $column => $label)
                @if($order->{$column})
                    <p class="text-xs text-gray-500 mt-2">{{ __('checkout.'.$label) }}: {{ $order->{$column}->format('d/m/Y H:i') }}</p>
                @endif
            @endforeach
        </section>

        {{-- Internal notes --}}
        @can('note', $order)
            <section class="{{ $card }}">
                <h2 class="{{ $heading }}">{{ __('checkout.internal_notes') }}</h2>
                <form method="POST" action="{{ route('admin.orders.note', $order) }}" class="space-y-2">
                    @csrf
                    <label for="internal-notes" class="sr-only">{{ __('checkout.internal_notes') }}</label>
                    <textarea id="internal-notes" name="internal_notes" rows="4"
                              class="w-full bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50">{{ old('internal_notes', $order->internal_notes) }}</textarea>
                    <p class="text-xs text-gray-600">{{ __('checkout.internal_notes_help') }}</p>
                    <button type="submit" class="{{ $secondaryButton }}">{{ __('checkout.add_note') }}</button>
                </form>
            </section>
        @endcan
    </div>
</div>
@endsection
