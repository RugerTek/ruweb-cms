@extends('admin.layouts.app')

@section('title', __('connectors.title'))

@section('content')
<div class="mb-8 max-w-3xl">
    <h1 class="text-lg font-semibold text-white">{{ __('connectors.title') }}</h1>
    <p class="mt-1 text-sm leading-relaxed text-gray-400">{{ __('connectors.intro_lead') }}</p>
</div>

@if($errors->any())
    <div class="mb-4 p-3.5 bg-red-500/10 border border-red-500/20 text-red-300 rounded-md text-sm" role="alert">
        @foreach($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

@if($groups === [])
    <div class="text-center py-20 text-gray-600">
        @include('admin.connectors.icons.generic', ['class' => 'w-12 h-12 mx-auto mb-4'])
        <p class="text-sm font-medium text-gray-500">{{ __('connectors.empty') }}</p>
    </div>
@else
    {{--
        One page grid (1 column on phones, 2 on tablets, 3 on desktop). Each group spans as many columns as it has
        cards, so small groups sit side by side instead of leaving the row empty. A group is a subgrid of two rows
        (heading, cards): headings and cards line up across the groups of the same row, and the cards of a row keep
        the same height.
    --}}
    <div class="grid grid-cols-1 gap-x-6 gap-y-10 md:grid-cols-2 xl:grid-cols-3">
        @foreach($groups as $group)
            @php
                $count = count($group['cards']);
                $span = match (min($count, 3)) {
                    1 => 'md:col-span-1 xl:col-span-1',
                    2 => 'md:col-span-2 xl:col-span-2',
                    default => 'md:col-span-2 xl:col-span-3',
                };
                $cols = match (min($count, 3)) {
                    1 => 'grid-cols-1',
                    2 => 'grid-cols-1 md:grid-cols-2',
                    default => 'grid-cols-1 md:grid-cols-2 xl:grid-cols-3',
                };
                $value = $group['category']->value;
            @endphp
            <section class="row-span-2 grid grid-rows-subgrid gap-y-4 {{ $span }}" data-connector-group="{{ $value }}" aria-labelledby="connector-group-{{ $value }}">
                <header class="border-t border-white/[0.08] pt-4">
                    <h2 id="connector-group-{{ $value }}" class="text-sm font-semibold text-white">{{ __($group['category']->label()) }}</h2>
                    <p class="mt-1 text-sm text-gray-400">{{ __('connectors.category_'.$value.'_hint') }}</p>
                </header>
                <div class="grid auto-rows-fr gap-6 {{ $cols }}">
                    @foreach($group['cards'] as $card)
                        @include('admin.connectors._card', $card)
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
@endsection
