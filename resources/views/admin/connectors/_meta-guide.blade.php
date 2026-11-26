{{--
    Step by step guide to add the feed in Meta Commerce Manager (PLAN.md §4.6), with the labels of its interface
    (verified 2026-11-24, docs/operations/connectors.md). Receives $guideLinks
    (Admin\ConnectorController::guideLinks()), $feedUrl and $currency from the detail view.
--}}
@php
    $anchor = fn (string $url, string $label): string => '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="text-sky-400 hover:text-sky-300 underline underline-offset-2">'.e($label).'</a>';
    // Translated sentence escaped first; then the :link placeholder becomes a trusted anchor.
    $withLink = fn (string $key, string $link, array $replace = []): string => str_replace('@@link@@', $link, e(__($key, $replace + ['link' => '@@link@@'])));
    $steps = [
        $withLink('connectors.guide_step_open', $anchor($guideLinks['COMMERCE_MANAGER_URL'], __('connectors.guide_link_commerce_manager'))),
        e(__('connectors.guide_step_data_source')),
        e(__('connectors.guide_step_platform')),
        e(__('connectors.guide_step_url')),
        e(__('connectors.guide_step_name_currency', ['currency' => $currency])),
        e(__('connectors.guide_step_schedule')).' ('.$anchor($guideLinks['HELP_SCHEDULED_FEEDS_URL'], __('connectors.guide_link_scheduled')).')',
        $withLink('connectors.guide_step_result', $anchor($guideLinks['HELP_TROUBLESHOOT_URL'], __('connectors.guide_link_troubleshoot'))),
        e(__('connectors.guide_step_products')),
        e(__('connectors.guide_step_return')),
    ];
@endphp
<section class="bg-[#141414] ring-1 ring-white/[0.06] rounded-xl p-5" aria-labelledby="meta-guide-title" data-meta-guide>
    <h2 id="meta-guide-title" class="text-sm font-semibold text-white">{{ __('connectors.guide_title') }}</h2>
    <p class="text-xs text-gray-500 mt-1 mb-4">{{ __('connectors.guide_requirement') }}</p>

    <ol class="space-y-4">
        @foreach($steps as $index => $step)
            <li class="flex gap-3">
                <span class="flex-shrink-0 w-6 h-6 rounded-full bg-sky-500/10 text-sky-400 text-xs font-semibold flex items-center justify-center">{{ $index + 1 }}</span>
                <div class="flex-1 min-w-0 pt-0.5">
                    <p class="text-sm text-gray-300">{!! $step !!}</p>
                    @if($index === 3 && $feedUrl)
                        <div class="flex flex-col sm:flex-row gap-2 mt-2">
                            <label for="guide-feed-url" class="sr-only">{{ __('connectors.feed_url') }}</label>
                            <input type="text" id="guide-feed-url" readonly value="{{ $feedUrl }}" onclick="this.select()"
                                   class="w-full bg-[#1a1a1a] border border-white/10 text-gray-300 font-mono text-xs rounded-md px-3 py-2 focus:outline-none select-all">
                            <button type="button" data-copy-target="guide-feed-url" data-copied="{{ __('connectors.copied') }}"
                                    class="shrink-0 inline-flex items-center justify-center gap-2 px-3 py-2 bg-[#1a1a1a] border border-white/10 hover:border-white/20 text-gray-300 text-sm font-medium rounded-md transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                <span data-copy-label aria-live="polite">{{ __('connectors.action_copy') }}</span>
                            </button>
                        </div>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>

    <p class="text-xs text-gray-500 mt-4" data-meta-guide-labels>{{ __('connectors.guide_labels') }}</p>
</section>
