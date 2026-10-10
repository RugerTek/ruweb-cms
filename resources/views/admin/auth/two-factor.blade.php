@extends('admin.layouts.guest')
@section('title', __('security.2fa'))
@section('content')
<h1 class="text-xl font-semibold text-white mb-3">{{ __('security.2fa') }}</h1>
<p class="text-sm text-gray-400 mb-4">{{ __('security.challenge_hint') }}</p>
<form method="POST" action="{{ route('admin.two-factor.verify') }}">
    @csrf
    <label for="code" class="block text-sm text-gray-300">{{ __('security.code') }}</label>
    <input id="code" name="code" type="text" autocomplete="one-time-code" required autofocus class="w-full bg-[#1a1a1a] text-white border border-white/10 rounded-md px-3 py-2 my-2">
    @error('code') <p class="text-sm text-red-400" role="alert">{{ $message }}</p> @enderror
    <button class="bg-sky-600 text-white rounded-md px-4 py-2 mt-3" type="submit">{{ __('security.verify') }}</button>
</form>
@endsection
