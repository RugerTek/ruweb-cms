@extends('layouts.public')

@push('head')
    <meta name="robots" content="noindex, nofollow">
@endpush

@use('App\Services\Shop\CartService')

@section('content')
@php
    $shopIndexHref = CartService::route('index');
@endphp
<section class="py-14 sm:py-16" data-shop-cart>
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <h1 class="text-3xl sm:text-4xl font-semibold tracking-tight text-gray-900">{{ __('checkout.cart_title') }}</h1>

        @if(session('status'))
            <p class="mt-6 rounded-lg border border-sky-200 bg-sky-50 px-4 py-2 text-sm text-sky-800" role="status">{{ session('status') }}</p>
        @endif
        @if($errors->any())
            <div class="mt-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert" data-cart-errors>
                <ul class="list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if($cart['empty'])
            <div class="mt-8 rounded-xl border border-gray-200 p-8 text-center text-gray-500">
                <p>{{ __('checkout.cart_empty') }}</p>
                <a href="{{ $shopIndexHref }}" class="mt-4 inline-flex text-sm font-medium text-sky-700 hover:text-sky-800">← {{ __('checkout.continue_shopping') }}</a>
            </div>
        @else
            <div class="mt-8 grid grid-cols-1 gap-10 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <ul class="divide-y divide-gray-200 border-y border-gray-200">
                        @foreach($cart['lines'] as $line)
                            @php $problem = CartService::problemMessage($line['problem'], $line['available']); @endphp
                            <li class="flex flex-col gap-4 py-5 sm:flex-row sm:items-start sm:justify-between" data-cart-line="{{ $line['item']->id }}" @if($line['problem']) data-cart-problem="{{ $line['problem'] }}" @endif>
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-900">
                                        @if($line['url'])
                                            <a href="{{ $line['url'] }}" class="hover:text-sky-700 transition-colors">{{ $line['title'] }}</a>
                                        @else
                                            {{ $line['title'] }}
                                        @endif
                                    </p>
                                    @if($line['variant_name'])
                                        <p class="text-sm text-gray-500">{{ $line['variant_name'] }}</p>
                                    @endif
                                    @if($line['unit_price'])
                                        <p class="mt-1 text-sm text-gray-600"><span class="sr-only">{{ __('checkout.unit_price') }}:</span>{{ $line['unit_price']->format() }}</p>
                                    @endif
                                    @if($problem)
                                        <p class="mt-2 text-sm font-medium text-red-600">{{ $problem }}</p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-3 sm:shrink-0">
                                    <form method="POST" action="{{ CartService::route('cart.update', ['item' => $line['item']->id]) }}" class="flex items-center gap-2">
                                        @csrf
                                        @method('PATCH')
                                        <label for="quantity-{{ $line['item']->id }}" class="sr-only">{{ __('checkout.quantity') }}</label>
                                        <input id="quantity-{{ $line['item']->id }}" type="number" name="quantity" min="1" max="{{ CartService::MAX_QUANTITY }}" value="{{ $line['quantity'] }}"
                                               class="w-20 rounded-md border border-gray-200 px-3 py-2 text-sm focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/20">
                                        <button type="submit" class="rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-700 hover:border-gray-400">{{ __('checkout.cart_update') }}</button>
                                    </form>
                                    <form method="POST" action="{{ CartService::route('cart.remove', ['item' => $line['item']->id]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-sm text-gray-500 underline hover:text-gray-800">{{ __('checkout.cart_remove') }}</button>
                                    </form>
                                    @if($line['line_total'])
                                        <p class="w-28 text-right font-medium text-gray-900"><span class="sr-only">{{ __('checkout.line_total') }}:</span>{{ $line['line_total']->format() }}</p>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ $shopIndexHref }}" class="mt-6 inline-flex text-sm font-medium text-sky-700 hover:text-sky-800">← {{ __('checkout.continue_shopping') }}</a>
                </div>

                <aside class="rounded-xl border border-gray-200 bg-gray-50 p-5 h-fit">
                    <dl class="flex justify-between text-base font-semibold text-gray-900">
                        <dt>{{ __('checkout.subtotal') }}</dt>
                        <dd data-cart-subtotal>{{ $cart['subtotal']->format() }}</dd>
                    </dl>
                    @if($cart['blocked'])
                        <p class="mt-4 text-sm text-red-600" data-cart-blocked>{{ __('checkout.cart_blocked') }}</p>
                        <span class="mt-4 inline-flex w-full cursor-not-allowed justify-center rounded-md bg-gray-300 px-4 py-3 text-sm font-medium text-white" aria-disabled="true">{{ __('checkout.go_to_checkout') }}</span>
                    @else
                        <a href="{{ CartService::route('checkout.show') }}" data-go-to-checkout
                           class="mt-4 inline-flex w-full justify-center rounded-md bg-gray-900 px-4 py-3 text-sm font-medium text-white hover:bg-gray-700 transition-colors">
                            {{ __('checkout.go_to_checkout') }}
                        </a>
                    @endif
                </aside>
            </div>
        @endif
    </div>
</section>
@endsection
