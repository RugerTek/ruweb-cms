@extends('layouts.public')

@section('content')
@php($content = $page->sections()['content'] ?? [])
@if(($content['is_visible'] ?? 1) && filled($content['html'] ?? null))
    <div class="cms-composition">{{ app(\App\Support\CompositionRenderer::class)->html($content['html']) }}</div>
@endif
@endsection
