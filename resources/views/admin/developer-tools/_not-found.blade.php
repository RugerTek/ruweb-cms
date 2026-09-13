{{-- 404 monitor tab: paths that returned 404, and the shortcut that turns one into a rule.
     Contract in docs/operations/redirects.md. --}}
<section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
    <div class="px-4 py-3 border-b border-white/[0.06] flex items-start justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-white">{{ __('admin.not_found_monitor') }}</h2>
            <p class="text-xs text-gray-600 mt-0.5">{{ __('admin.not_found_monitor_subtitle') }}</p>
        </div>
        @if($notFoundHits->isNotEmpty())
            <form method="POST" action="{{ route('admin.redirects.not-found.clear') }}">
                @csrf @method('DELETE')
                <button type="submit" class="text-xs text-red-400 hover:text-red-300 whitespace-nowrap"
                        data-confirm="{{ __('admin.not_found_clear_confirm') }}"
                        data-confirm-title="{{ __('admin.not_found_clear') }}"
                        data-confirm-label="{{ __('admin.not_found_clear') }}"
                        data-confirm-tone="danger">
                    {{ __('admin.not_found_clear') }}
                </button>
            </form>
        @endif
    </div>
    @unless(config('cms.redirects.monitor_not_found', true))
        <p class="px-5 py-4 text-xs text-amber-300/80 border-b border-white/[0.06]">{{ __('admin.not_found_disabled') }}</p>
    @endunless
    @if($notFoundHits->isEmpty())
        <p class="px-5 py-10 text-center text-sm text-gray-600">{{ __('admin.not_found_empty') }}</p>
    @else
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-white/[0.06]">
                        <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.not_found_path') }}</th>
                        <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.not_found_hits') }}</th>
                        <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.not_found_last_seen') }}</th>
                        <th class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.not_found_referer') }}</th>
                        <th class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider px-4 py-3">{{ __('admin.col_actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/[0.04]">
                    @foreach($notFoundHits as $hit)
                        <tr class="hover:bg-white/[0.02] transition-colors">
                            <td class="px-4 py-3 text-sm text-gray-300 font-mono">{{ $hit->path }}</td>
                            <td class="px-4 py-3 text-sm text-gray-400">{{ number_format($hit->hit_count) }}</td>
                            <td class="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">{{ $hit->last_seen_at?->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 text-xs text-gray-600 max-w-64 truncate" title="{{ $hit->referer }}">{{ $hit->referer ?: '—' }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-3">
                                    {{-- Hands the path to the redirects tab, which prefills the form with it. --}}
                                    <a href="{{ route('admin.developer-tools.index', ['tab' => 'redirects', 'source' => $hit->path]) }}"
                                       class="text-sm text-sky-400 hover:text-sky-300">{{ __('admin.not_found_create_redirect') }}</a>
                                    <form method="POST" action="{{ route('admin.redirects.not-found.destroy', $hit) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-sm text-red-400 hover:text-red-300"
                                                data-confirm="{{ __('admin.not_found_confirm_delete', ['path' => $hit->path]) }}"
                                                data-confirm-title="{{ __('admin.btn_delete') }}"
                                                data-confirm-label="{{ __('admin.btn_delete') }}"
                                                data-confirm-tone="danger">
                                            {{ __('admin.btn_delete') }}
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4 border-t border-white/[0.06]">{{ $notFoundHits->links() }}</div>
    @endif
</section>
