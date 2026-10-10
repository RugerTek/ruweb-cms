@extends('admin.layouts.app')

@section('title', __('editorial.calendar'))

@section('content')
<div class="max-w-5xl mx-auto space-y-6">
    <header>
        <h1 class="text-lg font-semibold text-white">{{ __('editorial.calendar') }}</h1>
        <p class="text-sm text-gray-500 mt-1">{{ __('editorial.calendar_hint') }}</p>
    </header>

    <form method="GET" class="flex flex-wrap items-end gap-3 rounded-xl bg-[#141414] ring-1 ring-white/[0.06] p-4">
        <div>
            <label for="editorial-month" class="block text-xs font-medium text-gray-400 mb-2">{{ __('editorial.month') }}</label>
            <input id="editorial-month" type="month" name="month" value="{{ $month }}" required
                   class="bg-[#1a1a1a] border border-white/10 text-gray-200 text-sm rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-sky-500/50 focus:border-sky-500/50 [color-scheme:dark]">
        </div>
        <button type="submit" class="inline-flex items-center px-4 py-2 rounded-md bg-sky-600 hover:bg-sky-500 text-white text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-sky-400 focus:ring-offset-2 focus:ring-offset-[#141414]">{{ __('editorial.show') }}</button>
    </form>

    <section aria-label="{{ __('editorial.calendar') }}" class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl overflow-hidden">
        @if($items->isEmpty())
            <div class="px-6 py-16 text-center">
                <svg class="w-10 h-10 mx-auto text-gray-600 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 2v4m8-4v4M3 10h18M5 4h14a2 2 0 012 2v14a2 2 0 01-2 2H5a2 2 0 01-2-2V6a2 2 0 012-2z"/></svg>
                <p class="text-sm text-gray-400">{{ __('editorial.empty_calendar') }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="border-b border-white/[0.06] bg-white/[0.02]">
                        <tr>
                            @foreach(['title', 'status', 'publish_at', 'expires_at'] as $heading)
                                <th scope="col" class="px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider whitespace-nowrap">{{ __('editorial.'.$heading) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.04]">
                        @foreach($items as $item)
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-4 py-4">
                                    <a href="{{ app(\App\Support\AdminCapabilities::class)->contentUrl($item) }}" class="font-medium text-sky-400 hover:text-sky-300 focus:outline-none focus:underline">{{ $item->title }}</a>
                                    <p class="text-xs text-gray-500 mt-1">{{ strtoupper($item->locale) }}</p>
                                </td>
                                <td class="px-4 py-4 whitespace-nowrap"><span class="inline-flex px-2 py-1 text-xs font-medium rounded-md {{ $item->status === 'published' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-gray-500/10 text-gray-400' }}">{{ __('admin.status_'.$item->status) }}</span></td>
                                <td class="px-4 py-4 text-gray-300 whitespace-nowrap">@if($item->published_at)<time datetime="{{ $item->published_at->toIso8601String() }}">{{ $item->published_at->format('d/m/Y H:i') }}</time>@else<span class="text-gray-600">—</span>@endif</td>
                                <td class="px-4 py-4 text-gray-300 whitespace-nowrap">@if($item->expires_at)<time datetime="{{ $item->expires_at->toIso8601String() }}">{{ $item->expires_at->format('d/m/Y H:i') }}</time>@else<span class="text-gray-600">—</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
