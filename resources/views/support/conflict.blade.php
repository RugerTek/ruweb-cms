@extends('admin.layouts.guest')

@section('title', __('support.conflict_title'))

@section('content')
<div class="text-center">
    <p class="text-xs font-medium text-amber-400 uppercase tracking-widest mb-4">{{ __('support.brand') }}</p>
    <h1 class="text-xl font-semibold text-white">{{ __('support.conflict_title') }}</h1>
    <p class="text-sm text-gray-500 mt-2">{{ __('support.conflict_body', ['email' => $email]) }}</p>
</div>
@endsection
