@extends('admin.layouts.app')

@php
    // "Nueva marca", not "Nueva Marcas": the singular label when the type has one.
    $newTaxonomyLabel = __('admin.taxonomy_new', ['type' => \Illuminate\Support\Facades\Lang::has('admin.taxonomy_singular_'.$type) ? __('admin.taxonomy_singular_'.$type) : __('admin.taxonomy_type_'.$type)]);
@endphp

@section('title', __('admin.taxonomy_type_' . $type))

@section('content')
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-6 gap-4">
    <h1 class="text-lg font-semibold text-white">{{ __('admin.taxonomy_type_' . $type) }}</h1>

    <a href="{{ route('admin.taxonomies.create', $type) }}" class="inline-flex items-center gap-2 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
        </svg>
        {{ $newTaxonomyLabel }}
    </a>
</div>

<div class="mb-6">
    <form method="GET" class="flex gap-2">
        <input
            type="text"
            name="search"
            value="{{ request('search') }}"
            placeholder="{{ __('admin.search_placeholder') }}"
            class="flex-1 bg-[#1a1a1a] border border-white/10 text-white text-sm rounded-md px-3 py-2 placeholder-gray-600 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50"
        >
        <button type="submit" class="px-3 py-2 bg-gray-800 border border-white/10 text-gray-400 text-sm rounded-md hover:bg-gray-700 transition-colors">
            {{ __('admin.search') }}
        </button>
    </form>
</div>

@if ($taxonomies->isEmpty())
    <div class="text-center py-20 text-gray-600">
        <svg class="w-12 h-12 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
        </svg>
        <p class="text-sm font-medium text-gray-500">{{ __('admin.taxonomy_none', ['type' => strtolower(__('admin.taxonomy_type_' . $type))]) }}</p>
        <a href="{{ route('admin.taxonomies.create', $type) }}" class="mt-4 inline-flex items-center gap-2 px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
            {{ $newTaxonomyLabel }}
        </a>
    </div>
@else
    <div class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-hidden">
        <table class="w-full">
            <thead>
                <tr class="border-b border-white/[0.06]">
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.col_title') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden sm:table-cell">{{ __('admin.col_slug') }}</th>
                    <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3 hidden md:table-cell">{{ __('admin.language') }}</th>
                    <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.col_actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-white/[0.04]">
                @foreach ($taxonomies as $taxonomy)
                    <tr class="hover:bg-white/[0.02] transition-colors">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.taxonomies.edit', [$type, $taxonomy]) }}" class="text-sm text-white hover:text-sky-400 transition-colors">
                                {{ $taxonomy->name }}
                            </a>
                            @if ($taxonomy->children->isNotEmpty())
                                <span class="ml-2 text-xs text-gray-600">{{ $taxonomy->children->count() }} {{ __('admin.taxonomy_children') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 hidden sm:table-cell">
                            <span class="text-sm text-gray-500">{{ $taxonomy->slug }}</span>
                        </td>
                        <td class="px-4 py-3 hidden md:table-cell">
                            <div class="flex items-center gap-1">
                                @foreach(($locales ?? collect()) as $locale)
                                    @php
                                        $state = $taxonomy->derivedTranslationState($locale->code);
                                        $translation = $taxonomy->translations->firstWhere('locale', $locale->code);
                                        $badgeColor = $state === 'published'
                                            ? 'bg-sky-500/10 text-sky-400'
                                            : ($state === 'missing' ? 'bg-white/[0.04] text-gray-600' : 'bg-yellow-500/10 text-yellow-400');
                                    @endphp
                                    @if($translation)
                                        <a href="{{ route('admin.taxonomies.edit', [$type, $translation]) }}"
                                           title="{{ __('admin.edit_translation', ['lang' => $locale->name]) }}"
                                           class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium transition-colors hover:ring-1 hover:ring-white/20 {{ $badgeColor }}">
                                            {{ strtoupper($locale->code) }}
                                        </a>
                                    @else
                                        <form method="POST" action="{{ route('admin.taxonomies.translate', [$type, $taxonomy, $locale->code]) }}" class="inline-flex">
                                            @csrf
                                            <button type="submit"
                                                    title="{{ __('admin.create_translation', ['lang' => $locale->name]) }}"
                                                    class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium transition-colors hover:bg-white/[0.08] hover:text-gray-300 {{ $badgeColor }}">
                                                +{{ strtoupper($locale->code) }}
                                            </button>
                                        </form>
                                    @endif
                                @endforeach
                            </div>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('admin.taxonomies.edit', [$type, $taxonomy]) }}" class="text-gray-600 hover:text-gray-400 transition-colors" title="{{ __('admin.action_edit') }}">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                    </svg>
                                </a>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $taxonomies->links() }}
    </div>
@endif
@endsection
