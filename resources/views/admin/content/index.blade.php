@extends('admin.layouts.app')
@section('title', __($label))
@section('content')
<div data-content-list class="space-y-6">
    <h1 class="text-lg font-semibold text-white">{{ __($label) }}</h1>
    <p class="text-sm text-gray-400">{{ __('admin.essential_content_hint') }}</p>
    <form method="GET" class="flex flex-wrap gap-3">
        <label class="sr-only" for="content-search">{{ __('admin.search') }}</label>
        <input id="content-search" name="search" value="{{ request('search') }}" placeholder="{{ __('admin.search_placeholder') }}" class="min-w-0 flex-1 rounded-md border border-white/10 bg-[#1a1a1a] px-3 py-2 text-sm text-white">
        <label class="sr-only" for="content-state">{{ __('admin.col_status') }}</label>
        <select id="content-state" name="status" class="rounded-md border border-white/10 bg-[#1a1a1a] px-3 py-2 text-sm text-white">
            <option value="">{{ __('admin.all') }}</option>
            @foreach(['draft', 'published', 'scheduled', 'expired'] as $state)
                <option value="{{ $state }}" @selected(request('status') === $state)>{{ __('admin.'.$state) }}</option>
            @endforeach
        </select>
        <button class="rounded-md bg-sky-600 px-3 py-2 text-sm text-white">{{ __('admin.search') }}</button>
    </form>
    <div class="overflow-x-auto rounded-xl bg-[#141414] ring-1 ring-white/[0.06]">
        <table class="w-full text-left text-sm">
            <thead><tr class="border-b border-white/10">@foreach(['col_title', 'language', 'col_status', 'col_actions'] as $heading)<th scope="col" class="p-4 text-xs font-medium text-gray-500">{{ __('admin.'.$heading) }}</th>@endforeach</tr></thead>
            <tbody class="divide-y divide-white/[0.06]">
                @forelse($items as $item)
                    <tr>
                        <td class="p-4"><a class="text-white hover:text-sky-400" href="{{ app(\App\Support\AdminCapabilities::class)->contentUrl($item) }}">{{ $item->title }}</a><p class="mt-1 text-xs text-gray-500 break-words">/{{ $item->slug }}</p></td>
                        <td class="p-4 text-gray-400">{{ strtoupper($item->locale) }}</td>
                        <td class="p-4 text-gray-400">{{ __('admin.'.app(\App\Support\AdminCapabilities::class)->publicationState($item)) }}</td>
                        <td class="p-4"><div class="flex flex-wrap gap-3">
                            <a class="text-sky-400" href="{{ route('admin.content.preview', [$type, $item->id]) }}" target="_blank" rel="noopener">{{ __('admin.action_preview') }}</a>
                            @if($item->isPublished())<a class="text-gray-400" href="{{ $item->url() }}" target="_blank" rel="noopener">{{ __('admin.action_view') }}</a>@endif
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="p-8 text-center text-gray-500">{{ __('admin.content_no_results') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $items->links() }}
</div>
@endsection
