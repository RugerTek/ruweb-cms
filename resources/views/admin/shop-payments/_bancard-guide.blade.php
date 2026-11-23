{{--
    Step-by-step guide to get Bancard working (PLAN.md E3.4, items 7 and 8), for a business owner: ask Bancard for the
    vPOS, test keys, confirmation URL, test purchase, the portal's "test list", certification and production keys.
    Then what Bancard checks on the site before certifying it (informative, never blocks), each with where to solve
    it. Receives $siteRequirements from Admin\ShopPaymentsController.
--}}
@php
    // Texts carry Bancard's public URLs: make them clickable (escaped first, a trailing period stays outside).
    $linkify = fn (string $text): string => preg_replace(
        '~https://[A-Za-z0-9./_-]*[A-Za-z0-9/_-]~',
        '<a href="$0" target="_blank" rel="noopener noreferrer" class="text-sky-400 hover:text-sky-300 underline underline-offset-2">$0</a>',
        e($text),
    );
@endphp
<div class="grid gap-4 lg:grid-cols-2">
    <div class="rounded-lg bg-white/[0.02] ring-1 ring-white/[0.06] p-4" data-bancard-guide>
        <h3 class="text-sm font-semibold text-white">{{ __('checkout.guide_title') }}</h3>
        <ol class="mt-3 space-y-2 text-sm text-gray-300 list-decimal list-inside">
            @foreach (range(1, 8) as $step)
                <li>{!! $linkify(__('checkout.guide_step_'.$step)) !!}</li>
            @endforeach
        </ol>
        <p class="mt-3 text-xs text-amber-400">{{ __('checkout.guide_staging_reminder') }}</p>
    </div>

    <div class="rounded-lg bg-white/[0.02] ring-1 ring-white/[0.06] p-4" data-bancard-site-requirements>
        <h3 class="text-sm font-semibold text-white">{{ __('checkout.requirements_title') }}</h3>
        <ul class="mt-3 space-y-2 text-sm text-gray-300">
            @foreach ($siteRequirements as $requirement)
                <li class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <span class="flex-1 min-w-[12rem]">{{ __('checkout.requirement_'.$requirement['key']) }}</span>
                    @foreach ($requirement['links'] as $label => $url)
                        <a href="{{ $url }}" class="text-xs text-sky-400 hover:text-sky-300">{{ __($label) }}</a>
                    @endforeach
                </li>
            @endforeach
        </ul>
    </div>
</div>
