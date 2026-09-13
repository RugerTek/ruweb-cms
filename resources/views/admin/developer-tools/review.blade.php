@extends('admin.layouts.app')

@section('title', __('admin.snapshot_review_title'))

@php
    $formatBytes = static function (?int $bytes): string {
        if ($bytes === null) {
            return 'N/A';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $value = max(0, $bytes);
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return round($value, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
    };

    $summary = $report->summary();
    $blocking = $report->blocking();
    $warnings = $report->warnings();
    $info = $report->info();
    $riskText = static fn ($risk): string => __()->has('admin.snapshot_risk_'.$risk->key) ? __('admin.snapshot_risk_'.$risk->key) : $risk->message;
@endphp

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <div>
        <a href="{{ route('admin.developer-tools.index', ['tab' => 'migration']) }}" class="text-xs text-gray-500 hover:text-gray-300">&larr; {{ __('admin.developer_tools') }}</a>
        <h1 class="text-lg font-semibold text-white mt-1">{{ __('admin.snapshot_review_title') }}</h1>
        <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.snapshot_review_help') }}</p>
    </div>

    <section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
        <div class="px-4 py-3 border-b border-white/[0.06]">
            <h2 class="text-sm font-semibold text-white">{{ __('admin.snapshot_summary') }}</h2>
        </div>
        <dl class="divide-y divide-white/[0.06]">
            @foreach([
                'original_name' => $original_name ?? $token,
                'version' => ($summary['version'] ?? 'N/A').' → '.($report->destination['version'] ?? 'N/A'),
                'format_version' => $summary['format_version'],
                'created_at' => $summary['created_at'],
                'origin_url' => $summary['origin_url'],
                'destination_url' => $report->destination['url'] ?? null,
                'origin_commit' => $summary['origin_commit'],
                'driver' => ($summary['driver'] ?? 'N/A').' → '.($report->destination['driver'] ?? 'N/A'),
                'tables' => $summary['tables'].' / '.$summary['rows'],
                'uploads' => $summary['uploads_files'].' · '.$formatBytes($summary['uploads_bytes']),
            ] as $field => $value)
                <div class="px-4 py-3 grid grid-cols-2 gap-4 text-sm">
                    <dt class="text-gray-500">{{ __('admin.snapshot_field_'.$field) }}</dt>
                    <dd class="text-gray-200 break-all">{{ $value ?? 'N/A' }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <form method="POST" action="{{ route('admin.developer-tools.restore', ['token' => $token]) }}" class="space-y-6"
          @if($blocking === []) data-confirm="{{ __('admin.snapshot_restore_confirm') }}" data-confirm-title="{{ __('admin.snapshot_restore_now') }}" data-confirm-label="{{ __('admin.snapshot_restore_now') }}" data-confirm-tone="danger" @endif>
        @csrf

        <section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
            <div class="px-4 py-3 border-b border-white/[0.06]">
                <h2 class="text-sm font-semibold text-white">{{ __('admin.snapshot_risks') }}</h2>
            </div>

            @if($report->risks === [])
                <p class="px-4 py-4 text-sm text-emerald-400">{{ __('admin.snapshot_no_risks') }}</p>
            @endif

            @if($blocking !== [])
                <div class="px-4 py-4 space-y-3 border-b border-white/[0.06] bg-red-500/[0.04]">
                    <div>
                        <h3 class="text-sm font-semibold text-red-400">{{ __('admin.snapshot_blocking_title') }}</h3>
                        <p class="text-xs text-gray-500 mt-0.5">{{ __('admin.snapshot_blocking_help') }}</p>
                    </div>
                    @foreach($blocking as $risk)
                        <div class="text-sm">
                            <p class="text-red-300">{{ $riskText($risk) }}</p>
                            <p class="text-xs text-gray-500 mt-0.5 break-all">[{{ $risk->key }}] {{ $risk->message }}</p>
                        </div>
                    @endforeach
                </div>
            @endif

            @foreach($warnings as $risk)
                <label class="px-4 py-4 flex items-start gap-3 border-b border-white/[0.06] text-sm cursor-pointer hover:bg-white/[0.02]">
                    <input type="checkbox" name="accepted[]" value="{{ $risk->key }}" class="mt-0.5 rounded border-white/[0.12] bg-[#0a0a0a]" @disabled($blocking !== [])>
                    <span class="flex-1">
                        <span class="block text-amber-300">{{ $riskText($risk) }}</span>
                        <span class="block text-xs text-gray-500 mt-0.5 break-all">[{{ $risk->key }}] {{ $risk->message }}</span>
                        <span class="block text-xs text-gray-400 mt-1">{{ __('admin.snapshot_warning_accept') }}</span>
                    </span>
                </label>
            @endforeach

            @foreach($info as $risk)
                <div class="px-4 py-3 text-sm border-b border-white/[0.06] last:border-b-0">
                    <p class="text-gray-300">{{ $riskText($risk) }}</p>
                    <p class="text-xs text-gray-500 mt-0.5 break-all">[{{ $risk->key }}] {{ $risk->message }}</p>
                </div>
            @endforeach
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" @disabled($blocking !== []) class="inline-flex items-center gap-2 px-3 py-2 bg-red-600 hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-sm font-medium rounded-md transition-colors">
                {{ __('admin.snapshot_restore_now') }}
            </button>
            <button type="submit" form="discard-{{ $token }}" class="inline-flex items-center gap-2 px-3 py-2 bg-white/[0.06] hover:bg-white/[0.1] text-gray-300 text-sm font-medium rounded-md transition-colors">
                {{ __('admin.snapshot_discard') }}
            </button>
        </div>
    </form>

    <form id="discard-{{ $token }}" method="POST" action="{{ route('admin.developer-tools.discard', ['token' => $token]) }}">
        @csrf
        @method('DELETE')
    </form>
</div>
@endsection
