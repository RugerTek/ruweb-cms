@extends('layouts.public')

@section('content')
@php($composition = $page->sections()['composition'] ?? [])
@if(($composition['is_visible'] ?? 1) && !empty($composition['blocks']))
    <div class="cms-composition">{{ app(\App\Support\CompositionRenderer::class)->blocks($composition['blocks']) }}</div>
@endif
@endsection
