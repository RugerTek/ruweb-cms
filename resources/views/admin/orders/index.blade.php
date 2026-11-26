@extends('admin.layouts.app')

@section('title', __('checkout.orders_title'))

@section('content')
@php
    $statusBadge = [
        'pending' => 'bg-yellow-500/10 text-yellow-400',
        'paid' => 'bg-sky-500/10 text-sky-400',
        'fulfilled' => 'bg-emerald-500/10 text-emerald-400',
        'cancelled' => 'bg-white/[0.06] text-gray-400',
        'refunded' => 'bg-purple-500/10 text-purple-300',
    ];
    $filterQuery = array_filter(['method' => $filters['method'], 'from' => $filters['from']?->format('Y-m-d'), 'to' => $filters['to']?->format('Y-m-d')]);
@endphp
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-4">
    <h1 class="text-lg font-semibold text-white">{{ __('checkout.orders_title') }}</h1>
</div>

<div class="flex gap-1 mb-6 border-b border-white/[0.06] overflow-x-auto">
    <a href="{{ route('admin.orders.index', $filterQuery) }}"
       class="px-3 py-2 text-sm font-medium border-b-2 whitespace-nowrap transition-colors {{ $filters['status'] === null ? 'border-sky-500 text-sky-400' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
        {{ __('checkout.filter_all') }} <span class="text-xs text-gray-600 ml-1">{{ $totalCount }}</span>
    </a>
    @foreach(\App\Models\Order::STATUSES as $status)
        <a href="{{ route('admin.orders.index', $filterQuery + ['status' => $status]) }}"
           class="px-3 py-2 text-sm font-medium border-b-2 whitespace-nowrap transition-colors {{ $filters['status'] === $status ? 'border-sky-500 text-sky-400' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
            {{ __('checkout.status_'.$status) }} <span class="text-xs text-gray-600 ml-1">{{ $counts[$status] ?? 0 }}</span>
        </a>
    @endforeach
</div>

<div class="mb-6">
    <form method="GET" action="{{ route('admin.orders.index') }}" class="flex flex-col sm:flex-row sm:items-end gap-2">
        @if($filters['status'])
            <input type="hidden" name="status" value="{{ $filters['status'] }}">
        @endif
        <div class="flex flex-col gap-1">
            <label for="order-method" class="text-xs text-gray-500">{{ __('checkout.filter_method') }}</label>
            <select id="order-method" name="method" class="bg-[#1a1a1a] border border-white/10 text-gray-300 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50">
                <option value="">{{ __('checkout.filter_all') }}</option>
                @foreach($methods as $method)
                    <option value="{{ $method->key }}" @selected($filters['method'] === $method->key)>{{ $methodLabels[$method->key] ?? $method->key }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex flex-col gap-1">
            <label for="order-from" class="text-xs text-gray-500">{{ __('checkout.filter_date_from') }}</label>
            <input type="date" id="order-from" name="from" value="{{ $filters['from']?->format('Y-m-d') }}"
                   class="bg-[#1a1a1a] border border-white/10 text-gray-300 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 [color-scheme:dark]">
        </div>
        <div class="flex flex-col gap-1">
            <label for="order-to" class="text-xs text-gray-500">{{ __('checkout.filter_date_to') }}</label>
            <input type="date" id="order-to" name="to" value="{{ $filters['to']?->format('Y-m-d') }}"
                   class="bg-[#1a1a1a] border border-white/10 text-gray-300 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 [color-scheme:dark]">
        </div>
        <button type="submit" class="px-3 py-2 bg-gray-800 border border-white/10 text-gray-400 text-sm rounded-md hover:bg-gray-700 transition-colors">
            {{ __('checkout.filter_apply') }}
        </button>
    </form>
</div>

@if($orders->isEmpty())
    <div class="text-center py-20 text-gray-600">
        <svg class="w-12 h-12 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
        </svg>
        <p class="text-sm font-medium text-gray-500">{{ $hasOrders ? __('checkout.orders_none_found') : __('checkout.orders_empty') }}</p>
    </div>
@else
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/[0.06]">
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('checkout.col_number') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden md:table-cell">{{ __('checkout.col_customer') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('checkout.col_total') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden lg:table-cell">{{ __('checkout.col_method') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('checkout.col_status') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden sm:table-cell">{{ __('checkout.col_date') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/[0.04]">
                @foreach($orders as $order)
                    <tr class="hover:bg-white/[0.02] transition-colors">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.orders.show', $order) }}" class="text-sm text-white hover:text-sky-400 transition-colors font-mono">{{ $order->number }}</a>
                            <p class="text-xs text-gray-600 mt-0.5 md:hidden">{{ $order->customer_name }}</p>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell">
                            <p class="text-sm text-gray-300">{{ $order->customer_name }}</p>
                            <p class="text-xs text-gray-600 mt-0.5">{{ $order->customer_email }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-sm text-gray-300 whitespace-nowrap">{{ \App\Helpers\MoneyHelper::format($order->total_amount) }}</span>
                        </td>
                        <td class="px-4 py-3 hidden lg:table-cell">
                            <span class="text-sm text-gray-400">{{ $methodLabels[$order->payment_method_key] ?? $order->payment_method_key }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusBadge[$order->status] ?? 'bg-white/[0.06] text-gray-400' }}">{{ __('checkout.status_'.$order->status) }}</span>
                            @if(in_array($order->id, $lateOrderIds, true))
                                <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-500/10 text-red-400" data-late-payment>{{ __('checkout.late_payment_badge') }}</span>
                            @endif
                            @if(! empty($order->stock_shortfall))
                                <span class="inline-flex items-center mt-1 px-2 py-0.5 rounded-full text-xs font-medium bg-orange-500/10 text-orange-400">{{ __('checkout.stock_shortfall_badge') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="text-sm text-gray-500 whitespace-nowrap">{{ $order->placed_at?->format('d/m/Y H:i') }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $orders->links() }}
    </div>
@endif
@endsection
