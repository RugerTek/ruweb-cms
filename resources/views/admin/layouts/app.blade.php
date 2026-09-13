<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('admin.dashboard')) - {{ $siteName }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    @php
        $cmsMaxKb = max((int) config('cms.upload.image_max_size', 0), (int) config('cms.upload.document_max_size', 0));
        $toKb = static function ($value): int {
            $value = trim((string) $value);
            if ($value === '') {
                return 0;
            }

            $unit = strtolower(substr($value, -1));
            $number = (float) $value;

            return match ($unit) {
                'g' => (int) round($number * 1024 * 1024),
                'm' => (int) round($number * 1024),
                'k' => (int) round($number),
                default => (int) round($number / 1024),
            };
        };
        $uploadMaxKb = $toKb(ini_get('upload_max_filesize'));
        $postMaxKb = $toKb(ini_get('post_max_size'));
        $serverCaps = array_filter([$uploadMaxKb, $postMaxKb], static fn (int $value): bool => $value > 0);
        $serverMaxKb = count($serverCaps) > 0 ? min($serverCaps) : 0;
        $effectiveMaxKb = $serverMaxKb > 0 ? min($cmsMaxKb, $serverMaxKb) : $cmsMaxKb;

        $adminI18n = [
            'uploadFailed' => __('admin.upload_failed'),
            'validationFileMax' => __('admin.validation_file_max'),
            'validationFileUploaded' => __('admin.validation_file_uploaded'),
            'chooseFile' => __('admin.choose_file'),
            'noMediaFound' => __('admin.no_media_found'),
            'loadingMedia' => __('admin.loading_media'),
            'unableToLoadMediaItem' => __('admin.unable_to_load_media_item'),
            'unableToLoadMediaLibrary' => __('admin.unable_to_load_media_library'),
            'previous' => __('admin.previous'),
            'next' => __('admin.next'),
            'pageOf' => __('admin.page_of'),
            'confirmRemoveLogo' => __('admin.confirm_remove_logo'),
            'remove' => __('admin.remove'),
            'unsavedChangesTitle' => __('admin.unsaved_changes_title'),
            'unsavedChangesMessage' => __('admin.unsaved_changes_message'),
            'btnLeaveWithoutSaving' => __('admin.btn_leave_without_saving'),
            'btnKeepEditing' => __('admin.btn_keep_editing'),
            'logoItemFallbackLabel' => __('admin.logo_item_fallback_label'),
            'maxUploadKb' => $effectiveMaxKb,
        ];
    @endphp
    <script>
        window.adminI18n = {!! json_encode($adminI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};
    </script>
    @vite(['resources/js/admin.js'])
</head>
<body class="bg-[#0a0a0a] text-gray-100 min-h-screen font-sans antialiased">

    @include('admin.partials.sidebar')

    {{-- Mobile header --}}
    <header class="lg:hidden fixed top-0 left-0 right-0 z-40 flex items-center justify-between px-4 h-12 bg-[#111111] border-b border-white/[0.06]">
        <a href="{{ route('home') }}" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold text-white hover:text-gray-200 transition-colors">{{ $siteName }}</a>
        <div class="flex items-center gap-2">
            @include('admin.partials.cache-refresh-button')
            <button id="sidebar-toggle" class="text-gray-500 hover:text-gray-300 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
        </div>
    </header>

    <main class="flex-1 p-6 pt-16 lg:pt-6 lg:ml-56 min-h-screen">
        @if(auth()->user()?->isAdmin())
            <div class="hidden lg:flex items-center justify-end mb-4">
                @include('admin.partials.cache-refresh-button')
            </div>
        @endif
        @include('admin.partials.alerts')
        @yield('content')
    </main>

    @include('admin.media._library-modal')
    @include('admin.media._upload-modal')
    @include('admin.partials.confirm-modal')

    @stack('modals')
    @stack('scripts')

    <script>
        document.getElementById('sidebar-toggle')?.addEventListener('click', () => {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
        });
    </script>
</body>
</html>
