@extends('admin.layouts.app')

@section('title', __('admin.developer_tools'))

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

    $yesNo = static fn (bool $value): string => $value ? __('admin.yes') : __('admin.no');
@endphp

@section('content')
<div class="max-w-6xl mx-auto space-y-6">
    <div>
        <h1 class="text-lg font-semibold text-white">{{ __('admin.developer_tools') }}</h1>
        <p class="text-sm text-gray-500 mt-0.5">{{ __('admin.developer_tools_subtitle') }}</p>
    </div>

    <div class="flex gap-2 border-b border-white/[0.08]">
        <a href="{{ route('admin.developer-tools.index', ['tab' => 'system']) }}"
           class="px-3 py-2 text-sm border-b-2 transition-colors {{ $activeTab === 'system' ? 'border-sky-500 text-sky-400' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
            {{ __('admin.developer_tools_system') }}
        </a>
        <a href="{{ route('admin.developer-tools.index', ['tab' => 'migration']) }}"
           class="px-3 py-2 text-sm border-b-2 transition-colors {{ $activeTab === 'migration' ? 'border-sky-500 text-sky-400' : 'border-transparent text-gray-500 hover:text-gray-300' }}">
            {{ __('admin.developer_tools_migration') }}
        </a>
    </div>

    @if($activeTab === 'system')
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-white/[0.06]">
                    <h2 class="text-sm font-semibold text-white">{{ __('admin.system_app') }}</h2>
                </div>
                <dl class="divide-y divide-white/[0.06]">
                    @foreach($report['app'] as $key => $value)
                        <div class="px-4 py-3 grid grid-cols-2 gap-4 text-sm">
                            <dt class="text-gray-500">{{ $key }}</dt>
                            <dd class="text-gray-200 break-all">{{ is_bool($value) ? $yesNo($value) : ($value ?? 'N/A') }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-white/[0.06]">
                    <h2 class="text-sm font-semibold text-white">{{ __('admin.system_database') }}</h2>
                </div>
                <dl class="divide-y divide-white/[0.06]">
                    @foreach($report['database'] as $key => $value)
                        <div class="px-4 py-3 grid grid-cols-2 gap-4 text-sm">
                            <dt class="text-gray-500">{{ $key }}</dt>
                            <dd class="text-gray-200 break-all">{{ $key === 'size' ? $formatBytes($value) : ($value ?? 'N/A') }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-white/[0.06]">
                    <h2 class="text-sm font-semibold text-white">{{ __('admin.system_php_limits') }}</h2>
                </div>
                <dl class="divide-y divide-white/[0.06]">
                    @foreach($report['php_limits'] as $key => $value)
                        <div class="px-4 py-3 grid grid-cols-2 gap-4 text-sm">
                            <dt class="text-gray-500">{{ $key }}</dt>
                            <dd class="text-gray-200">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-white/[0.06]">
                    <h2 class="text-sm font-semibold text-white">{{ __('admin.system_extensions') }}</h2>
                </div>
                <dl class="divide-y divide-white/[0.06]">
                    @foreach($report['extensions'] as $extension => $loaded)
                        <div class="px-4 py-3 flex items-center justify-between gap-4 text-sm">
                            <dt class="text-gray-500">{{ $extension }}</dt>
                            <dd class="{{ $loaded ? 'text-emerald-400' : 'text-red-400' }}">{{ $yesNo($loaded) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-white/[0.06]">
                    <h2 class="text-sm font-semibold text-white">{{ __('admin.system_storage') }}</h2>
                </div>
                <dl class="divide-y divide-white/[0.06]">
                    @foreach($report['storage'] as $key => $value)
                        <div class="px-4 py-3 grid grid-cols-2 gap-4 text-sm">
                            <dt class="text-gray-500">{{ $key }}</dt>
                            <dd class="text-gray-200 break-all">
                                @if(is_bool($value))
                                    {{ $yesNo($value) }}
                                @elseif($key === 'size')
                                    {{ $formatBytes($value) }}
                                @else
                                    {{ $value }}
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
                <div class="px-4 py-3 border-b border-white/[0.06]">
                    <h2 class="text-sm font-semibold text-white">{{ __('admin.system_media_health') }}</h2>
                </div>
                <dl class="divide-y divide-white/[0.06]">
                    @foreach($report['media_health'] as $key => $value)
                        <div class="px-4 py-3 grid grid-cols-2 gap-4 text-sm">
                            <dt class="text-gray-500">{{ $key }}</dt>
                            <dd class="text-gray-200 break-all">{{ is_bool($value) ? $yesNo($value) : $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        </div>
    @else
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="bg-[#111111] border border-white/[0.06] rounded-lg p-5 space-y-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">{{ __('admin.snapshot_download') }}</h2>
                    <p class="text-sm text-gray-500 mt-1">{{ __('admin.snapshot_download_help') }}</p>
                </div>
                <form method="POST" action="{{ route('admin.developer-tools.download') }}">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium rounded-md transition-colors">
                        {{ __('admin.snapshot_download') }}
                    </button>
                </form>
            </section>

            <section class="bg-[#111111] border border-white/[0.06] rounded-lg p-5 space-y-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">{{ __('admin.snapshot_upload') }}</h2>
                    <p class="text-sm text-gray-500 mt-1">{{ __('admin.snapshot_upload_help') }}</p>
                </div>
                <form method="POST" action="{{ route('admin.developer-tools.upload') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <input type="file" name="backup" accept=".appbackup" required class="block w-full text-sm text-gray-300 file:mr-4 file:rounded-md file:border-0 file:bg-white/[0.08] file:px-3 file:py-2 file:text-sm file:text-gray-200 hover:file:bg-white/[0.12]">
                    @error('backup')
                        <p class="text-sm text-red-400">{{ $message }}</p>
                    @enderror
                    <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 bg-white/[0.08] hover:bg-white/[0.12] text-white text-sm font-medium rounded-md transition-colors">
                        {{ __('admin.snapshot_upload') }}
                    </button>
                </form>
            </section>
        </div>

        @if($pending !== [])
            <section class="bg-[#111111] border border-white/[0.06] rounded-lg overflow-hidden">
                <div class="px-5 py-4 border-b border-white/[0.06]">
                    <h2 class="text-sm font-semibold text-white">{{ __('admin.snapshot_pending_title') }}</h2>
                    <p class="text-sm text-gray-500 mt-1">{{ __('admin.snapshot_pending_help') }}</p>
                </div>
                <ul class="divide-y divide-white/[0.06]">
                    @foreach($pending as $item)
                        @php $summary = $item['report']->summary(); @endphp
                        <li class="px-5 py-3 flex flex-wrap items-center gap-4 text-sm">
                            <div class="flex-1 min-w-0">
                                <p class="text-gray-200 truncate">{{ $item['original_name'] ?? $item['token'] }}</p>
                                <p class="text-gray-500 text-xs mt-0.5">
                                    {{ $summary['origin_url'] ?? 'N/A' }} · {{ $summary['created_at'] ?? 'N/A' }} · {{ $formatBytes($item['size']) }}
                                    · {{ count($item['report']->risks) }} {{ Str::lower(__('admin.snapshot_risks')) }}
                                </p>
                            </div>
                            <a href="{{ route('admin.developer-tools.review', ['token' => $item['token']]) }}" class="px-3 py-1.5 bg-sky-600 hover:bg-sky-700 text-white text-xs font-medium rounded-md transition-colors">
                                {{ __('admin.snapshot_pending_review') }}
                            </a>
                            <form method="POST" action="{{ route('admin.developer-tools.discard', ['token' => $item['token']]) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-3 py-1.5 bg-white/[0.06] hover:bg-white/[0.1] text-gray-300 text-xs font-medium rounded-md transition-colors">
                                    {{ __('admin.snapshot_discard') }}
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="bg-[#111111] border border-white/[0.06] rounded-lg p-5 space-y-4">
                <div>
                    <h2 class="text-sm font-semibold text-white">{{ __('admin.snapshot_housekeeping_title') }}</h2>
                    <p class="text-sm text-gray-500 mt-1">{{ __('admin.snapshot_housekeeping_help') }}</p>
                </div>
                <dl class="divide-y divide-white/[0.06] border border-white/[0.06] rounded-md">
                    @foreach($usage as $key => $stats)
                        <div class="px-4 py-2.5 grid grid-cols-2 gap-4 text-sm">
                            <dt class="text-gray-500">{{ __('admin.snapshot_housekeeping_'.$key) }}</dt>
                            <dd class="text-gray-200 text-right">{{ $stats['files'] }} · {{ $formatBytes($stats['bytes']) }}</dd>
                        </div>
                    @endforeach
                </dl>
                <form method="POST" action="{{ route('admin.developer-tools.prune') }}"
                      data-confirm="{{ __('admin.snapshot_prune_confirm') }}"
                      data-confirm-title="{{ __('admin.snapshot_prune') }}"
                      data-confirm-label="{{ __('admin.snapshot_prune') }}"
                      data-confirm-tone="primary">
                    @csrf
                    <button type="submit" class="inline-flex items-center gap-2 px-3 py-2 bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 border border-amber-500/20 text-sm font-medium rounded-md transition-colors">
                        {{ __('admin.snapshot_prune') }}
                    </button>
                </form>
            </section>

            <section class="bg-[#111111] border border-white/[0.06] rounded-lg p-5">
                <h2 class="text-sm font-semibold text-white">{{ __('admin.snapshot_cli_title') }}</h2>
                <p class="text-sm text-gray-500 mt-1">{{ __('admin.snapshot_cli_help') }}</p>
                <pre class="mt-4 overflow-x-auto rounded-md bg-black/40 p-4 text-xs text-gray-300"><code>php artisan snapshot:create --name=origin
php artisan snapshot:inspect /path/to/origin.appbackup
php artisan snapshot:restore /path/to/origin.appbackup
php artisan snapshot:prune</code></pre>
            </section>
        </div>
    @endif
</div>
@endsection
