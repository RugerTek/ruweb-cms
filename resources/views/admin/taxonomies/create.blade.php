@extends('admin.layouts.app')

@php
    // "Nueva marca", not "Nueva Marcas": the singular label when the type has one.
    $newTaxonomyLabel = __('admin.taxonomy_new', ['type' => \Illuminate\Support\Facades\Lang::has('admin.taxonomy_singular_'.$type) ? __('admin.taxonomy_singular_'.$type) : __('admin.taxonomy_type_'.$type)]);
@endphp

@section('title', $newTaxonomyLabel)

@section('content')
<div class="mb-6">
    <div class="flex items-center gap-2 text-sm text-gray-500 mb-2">
        <a href="{{ route('admin.taxonomies.index', $type) }}" class="hover:text-gray-300 transition-colors">{{ __('admin.taxonomy_type_' . $type) }}</a>
        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <span class="text-gray-400">{{ $newTaxonomyLabel }}</span>
    </div>
    <h1 class="text-lg font-semibold text-white">{{ $newTaxonomyLabel }}</h1>
</div>

<div class="max-w-lg">
    @include('admin.taxonomies._form', ['taxonomy' => null])
</div>
@endsection
