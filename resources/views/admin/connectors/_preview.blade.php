{{--
    Feed preview on the connector detail (PLAN.md §4.4, point 5): the first items as the platform receives them,
    the same items written by the connector ("View XML"), every skipped item with its reason and an edit link, and
    the notices that do not skip. Receives $preview (FeedResult|null), $settings and $xml from the detail view.
--}}
@php
    // Compact below 640px: tighter cells, and the link column folds into the title (one short line per item).
    $th = 'text-left text-xs font-medium text-gray-500 uppercase tracking-wider px-3 py-2 sm:px-4 sm:py-3';
    $td = 'px-3 py-2 sm:px-4 sm:py-3';
    $origin = \App\Support\SiteUrl::origin();
    $money = fn (?\App\ValueObjects\Money $value): ?string => $value === null ? null : $value->amount.' '.$value->currency;
    $availability = function (?string $value): string {
        if ($value === null) {
            return '—';
        }
        $key = 'connectors.availability_'.str_replace(' ', '_', $value);

        return __($key) === $key ? $value : __($key);
    };
    $byItem = fn (array $warnings) => collect($warnings)->groupBy('itemId');
    $images = $preview === null ? collect() : \App\Models\Media::query()
        ->whereIn('id', collect($preview->sample)->pluck('imageMediaId')->filter()->unique()->all())
        ->get()->keyBy('id');
@endphp
<section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5 space-y-4" aria-labelledby="preview-title" data-preview>
    <div>
        <h2 id="preview-title" class="text-sm font-semibold text-white">{{ __('connectors.preview_title') }}</h2>
        @if($preview !== null)
            <p class="text-xs text-gray-500 mt-1">{{ __('connectors.preview_help', ['count' => \App\Http\Controllers\Admin\ConnectorController::PREVIEW_LIMIT]) }}</p>
        @endif
    </div>

    @if($preview === null)
        <p class="text-sm text-gray-400">{{ __('connectors.preview_unavailable') }}</p>
    @else
        <p class="text-sm text-gray-300">{{ trans_choice('connectors.included_count', $preview->included, ['count' => $preview->included]) }} · {{ trans_choice('connectors.skipped_count', $preview->skipped, ['count' => $preview->skipped]) }}</p>

        @if($preview->sample === [])
            <p class="text-sm text-gray-500">{{ __('connectors.preview_empty') }}</p>
        @else
            <div class="ring-1 ring-white/[0.06] rounded-lg overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-white/[0.06]">
                            <th class="{{ $th }}">{{ __('connectors.col_image') }}</th>
                            <th class="{{ $th }} hidden md:table-cell">{{ __('connectors.col_id') }}</th>
                            <th class="{{ $th }}">{{ __('connectors.col_title') }}</th>
                            <th class="{{ $th }}">{{ __('connectors.col_price') }}</th>
                            <th class="{{ $th }} hidden sm:table-cell">{{ __('connectors.col_availability') }}</th>
                            <th class="{{ $th }} hidden lg:table-cell">{{ __('connectors.col_brand') }}</th>
                            <th class="{{ $th }} hidden sm:table-cell">{{ __('connectors.col_link') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.04]">
                        @foreach($preview->sample as $item)
                            @php $image = $item->imageMediaId ? $images->get($item->imageMediaId) : null; @endphp
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="{{ $td }}">
                                    @if($image)
                                        <img src="{{ $image->url() }}" alt="" class="w-10 h-10 rounded object-cover bg-white/[0.04]" loading="lazy">
                                    @else
                                        <span class="block w-10 h-10 rounded bg-white/[0.04]"></span>
                                    @endif
                                </td>
                                <td class="{{ $td }} hidden md:table-cell text-xs text-gray-500 font-mono">{{ $item->id }}</td>
                                <td class="{{ $td }} text-sm text-white">
                                    <span class="hidden sm:inline">{{ $item->title }}</span>
                                    <a href="{{ $origin.$item->url }}" target="_blank" rel="noopener noreferrer" class="sm:hidden text-white underline decoration-white/30 underline-offset-2">{{ $item->title }}</a>
                                </td>
                                <td class="{{ $td }} text-sm text-gray-300 whitespace-nowrap">
                                    @if($item->salePrice)
                                        {{ $money($item->salePrice) }} <span class="block text-xs text-gray-500 line-through">{{ $money($item->price) }}</span>
                                    @else
                                        {{ $money($item->price) ?? '—' }}
                                    @endif
                                </td>
                                <td class="{{ $td }} hidden sm:table-cell text-sm text-gray-400">{{ $availability($item->availability ?? ($settings['availability'] ?? null)) }}</td>
                                <td class="{{ $td }} hidden lg:table-cell text-sm text-gray-400">{{ $item->brand ?? ($settings['brand'] ?? '—') }}</td>
                                <td class="{{ $td }} hidden sm:table-cell text-sm">
                                    <a href="{{ $origin.$item->url }}" target="_blank" rel="noopener noreferrer" class="text-sky-400 hover:text-sky-300 break-all">{{ $item->url }}</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if($xml !== null)
                <details class="group">
                    <summary class="inline-flex cursor-pointer items-center gap-2 px-3 py-2 bg-[#1a1a1a] border border-white/10 hover:border-white/20 text-gray-300 text-sm font-medium rounded-md transition-colors list-none">
                        <span class="group-open:hidden">{{ __('connectors.action_view_xml') }}</span>
                        <span class="hidden group-open:inline">{{ __('connectors.action_hide_xml') }}</span>
                    </summary>
                    <pre class="mt-3 max-h-96 overflow-auto bg-[#0f0f0f] ring-1 ring-white/[0.06] rounded-md p-3 text-xs text-gray-300 font-mono whitespace-pre-wrap break-all">{{ $xml }}</pre>
                </details>
            @endif
        @endif

        {{-- Skipped items (every one, with the reason and a link to fix it), then the notices that do not skip --}}
        @foreach([
            ['skipped', $preview->skippedWarnings(), 'connectors.skipped_title', 'connectors.skipped_help', 'connectors.skipped_empty', 'text-red-300'],
            ['notices', $preview->notices(), 'connectors.notices_title', 'connectors.notices_help', null, 'text-yellow-300/90'],
        ] as [$list, $warnings, $title, $help, $empty, $tone])
            @continue($warnings === [] && $empty === null)
            <div class="pt-2" data-warnings="{{ $list }}">
                <h3 class="text-sm font-semibold text-white">{{ __($title) }}</h3>
                <p class="text-xs text-gray-500 mt-1 mb-3">{{ __($help) }}</p>
                @if($warnings === [])
                    <p class="text-sm text-gray-500">{{ __($empty) }}</p>
                @else
                    <ul class="ring-1 ring-white/[0.06] rounded-lg divide-y divide-white/[0.04]">
                        @foreach($byItem($warnings) as $itemId => $itemWarnings)
                            @php $first = $itemWarnings->first(); @endphp
                            <li class="flex flex-col sm:flex-row sm:items-center gap-2 px-4 py-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm text-white">{{ $first->title }} <span class="text-xs text-gray-600 font-mono">{{ $itemId }}</span></p>
                                    @foreach($itemWarnings as $warning)
                                        <p class="text-xs {{ $tone }} mt-0.5">{{ __($warning->message(), $warning->context) }}</p>
                                    @endforeach
                                </div>
                                @if($first->editUrl)
                                    <a href="{{ $first->editUrl }}" class="shrink-0 inline-flex items-center px-3 py-1.5 bg-[#1a1a1a] border border-white/10 hover:border-white/20 text-gray-300 text-xs font-medium rounded-md transition-colors">{{ __('connectors.action_edit_product') }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    @endif
</section>
