@extends('admin.layouts.app')
@section('title', __('custom_code.test_preview'))
@section('content')
<h1 class="text-xl text-white">{{ __('custom_code.test_preview') }}</h1>
<p class="text-amber-300 my-4">{{ __('custom_code.test_warning') }}</p>
<iframe title="{{ __('custom_code.test_preview') }}" sandbox="allow-scripts" referrerpolicy="no-referrer" srcdoc="{{ $document }}" class="w-full h-96 bg-white"></iframe>
<pre class="text-gray-300 whitespace-pre-wrap max-w-full overflow-x-auto mt-4">{{ json_encode($evaluation, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
@endsection
