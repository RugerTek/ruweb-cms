@extends('admin.layouts.app')

@section('title', __('connectors.title'))

@section('content')
<div class="mb-6">
    <h1 class="text-lg font-semibold text-white">{{ __('connectors.title') }}</h1>
    <p class="text-sm text-gray-500 mt-0.5">{{ __('connectors.intro') }}</p>
</div>

@if($errors->any())
    <div class="mb-4 p-3.5 bg-red-500/10 border border-red-500/20 text-red-300 rounded-md text-sm" role="alert">
        @foreach($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

@if($cards === [])
    <div class="text-center py-20 text-gray-600">
        @include('admin.connectors.icons.generic', ['class' => 'w-12 h-12 mx-auto mb-4'])
        <p class="text-sm font-medium text-gray-500">{{ __('connectors.empty') }}</p>
    </div>
@else
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach($cards as $card)
            @include('admin.connectors._card', $card)
        @endforeach
    </div>
@endif
@endsection
