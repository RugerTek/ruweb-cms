<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="UTF-8">
    <script>if (window.self !== window.top) document.documentElement.hidden = true;</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('editor-title', __('admin.editor_title')) — {{ $siteName }}</title>
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
            'linkSearch' => __('admin.link_search'),
            'linkShowOptions' => __('admin.link_show_options'),
            'linkNoResults' => __('admin.link_no_results'),
            'validationErrors' => __('admin.validation_errors'),
            'fieldOutdated' => __('admin.field_outdated'),
            'fieldOutdatedHint' => __('admin.field_outdated_hint'),
            'markNoChanges' => __('admin.mark_no_changes'),
            'richTextLabels' => __('admin.rich_text_labels'),
            'maxUploadKb' => $effectiveMaxKb,
        ];
    @endphp
    <script>
        window.adminI18n = {!! json_encode($adminI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!};
    </script>
    @vite(['resources/js/admin.js'])
    <style>
        .editor-scroll::-webkit-scrollbar { width: 4px; height: 4px; }
        .editor-scroll::-webkit-scrollbar-track { background: transparent; }
        .editor-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 9999px; }
        .editor-scroll::-webkit-scrollbar-thumb:hover { background: rgba(16,185,129,0.5); }
        .editor-scroll { scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.12) transparent; }
    </style>
</head>
<body class="bg-[#0a0a0a] text-gray-100 font-sans antialiased h-screen flex flex-col overflow-hidden">

    {{-- Top bar --}}
    <header class="flex-none flex items-center justify-between px-4 h-12 bg-[#111111] border-b border-white/[0.06]">
        <div class="flex items-center gap-3 min-w-0 flex-1">
            <a href="{{ $editorBackHref ?? route('admin.pages.index') }}"
               class="text-gray-500 hover:text-gray-300 transition-colors flex-none"
               title="{{ $editorBackTitle ?? __('admin.back_to_pages') }}">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <span class="text-sm font-medium text-white truncate">@yield('editor-title', __('admin.editor_title'))</span>
        </div>

        <div class="flex items-center gap-2 flex-none">
            @yield('editor-actions')
            <button type="button" id="save-btn" disabled
                    class="px-3 py-1.5 bg-sky-600 hover:bg-sky-700 disabled:opacity-40 disabled:cursor-not-allowed text-white text-xs font-medium rounded-md transition-colors">
                {{ __('admin.btn_save_changes') }}
            </button>
        </div>
    </header>

    <div id="editor-lock-notice" role="alert" hidden
         class="flex-none px-4 py-3 text-sm text-amber-200 bg-amber-500/10 border-b border-amber-500/20">
        {{ __('admin.editor_lock_lost') }}
    </div>
    @if(isset($editorLease))
        <script>window.editorLease = {{ Illuminate\Support\Js::from($editorLease) }};</script>
    @endif

    {{-- Mobile tabs (only when preview is enabled) --}}
    @if($showPreview ?? true)
    <div class="lg:hidden flex-none flex bg-[#111111] border-b border-white/[0.06]">
        <button id="tab-edit"
                class="flex-1 py-2 text-xs font-medium text-sky-400 border-b-2 border-sky-500"
                onclick="editorSwitchTab('edit')">{{ __('admin.tab_edit') }}</button>
        <button id="tab-preview"
                class="flex-1 py-2 text-xs font-medium text-gray-500 border-b-2 border-transparent"
                onclick="editorSwitchTab('preview')">{{ __('admin.tab_preview') }}</button>
    </div>
    @endif

    {{-- Body --}}
    <div id="editor-workspace" class="flex-1 flex overflow-hidden">

        {{-- Edit panel --}}
        <aside id="panel-edit"
               class="w-full {{ ($showPreview ?? true) ? 'editor-resizable-panel lg:flex-none' : '' }} bg-[#111111] {{ ($showPreview ?? true) ? 'border-r border-white/[0.06]' : '' }} flex flex-col">
            <div class="flex-1 overflow-y-auto p-5 editor-scroll">
                @include('admin.partials.alerts')
                @yield('editor-form')
            </div>
            @yield('editor-footer')
        </aside>

        @if($showPreview ?? true)
        <div id="editor-divider" role="separator" tabindex="0" aria-orientation="vertical" aria-label="{{ __('admin.editor_resize') }}"
             aria-controls="panel-edit panel-preview" aria-valuemin="384" aria-valuenow="384" aria-valuemax="384"
             class="hidden lg:block flex-none w-2 bg-white/5 hover:bg-sky-500/30 focus-visible:bg-sky-500/40 cursor-col-resize touch-none"></div>
        @endif
        {{-- Preview panel --}}
        @if($showPreview ?? true)
        <section id="panel-preview"
                 class="hidden lg:flex flex-1 flex-col bg-[#0d0d0d]">
            <div class="flex-none flex items-center justify-between px-4 h-9 border-b border-white/[0.06]">
                <span class="text-xs text-gray-600">{{ __('admin.live_preview') }}</span>
                <div class="hidden lg:flex items-center gap-3">
                    <button id="vp-desktop" onclick="editorSetViewport('desktop')"
                            class="text-xs text-sky-400 transition-colors">{{ __('admin.viewport_desktop') }}</button>
                    <button id="vp-tablet" onclick="editorSetViewport('tablet')"
                            class="text-xs text-gray-600 hover:text-gray-400 transition-colors">{{ __('admin.viewport_tablet') }}</button>
                    <button id="vp-mobile" onclick="editorSetViewport('mobile')"
                            class="text-xs text-gray-600 hover:text-gray-400 transition-colors">{{ __('admin.viewport_mobile') }}</button>
                </div>
            </div>
            <div id="iframe-wrap"
                 class="flex-1 flex items-start justify-center overflow-auto bg-[#0d0d0d] editor-scroll">
                <iframe id="preview-frame"
                        class="w-full h-full border-0 bg-white"
                        sandbox="allow-same-origin allow-scripts"></iframe>
            </div>
        </section>
        @endif

    </div>

    {{-- Toast --}}
    <div id="editor-toast"
         class="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-4 py-2.5 rounded-lg text-sm font-medium shadow-lg pointer-events-none opacity-0 transition-opacity duration-300">
    </div>

    @include('admin.media._library-modal')
    @include('admin.media._upload-modal')
    @include('admin.partials.confirm-modal')

    @stack('modals')
    @stack('scripts')

    <script>
        function showToast(msg, type) {
            const el = document.getElementById('editor-toast');
            el.textContent = msg;
            el.className = 'fixed bottom-6 left-1/2 -translate-x-1/2 z-50 px-4 py-2.5 rounded-lg text-sm font-medium shadow-lg pointer-events-none transition-opacity duration-300 '
                + (type === 'error' ? 'bg-red-500/90 text-white' : 'bg-sky-600 text-white');
            el.style.opacity = '1';
            clearTimeout(el._timer);
            el._timer = setTimeout(() => { el.style.opacity = '0'; }, 2500);
        }

        function editorSwitchTab(tab) {
            const edit = document.getElementById('panel-edit');
            const preview = document.getElementById('panel-preview');
            const tabEdit = document.getElementById('tab-edit');
            const tabPreview = document.getElementById('tab-preview');

            const activeClass = 'flex-1 py-2 text-xs font-medium text-sky-400 border-b-2 border-sky-500';
            const inactiveClass = 'flex-1 py-2 text-xs font-medium text-gray-500 border-b-2 border-transparent';

            if (tab === 'edit') {
                edit.classList.remove('hidden');
                preview.classList.add('hidden');
                preview.classList.remove('flex');
                tabEdit.className = activeClass;
                tabPreview.className = inactiveClass;
            } else {
                edit.classList.add('hidden');
                preview.classList.remove('hidden');
                preview.classList.add('flex');
                tabPreview.className = activeClass;
                tabEdit.className = inactiveClass;
            }
        }

        function editorSetViewport(vp) {
            const iframe = document.getElementById('preview-frame');
            const wrap = document.getElementById('iframe-wrap');
            const desktopBtn = document.getElementById('vp-desktop');
            const tabletBtn = document.getElementById('vp-tablet');
            const mobileBtn = document.getElementById('vp-mobile');

            if (vp === 'mobile') {
                iframe.className = 'border-0 bg-white rounded-2xl shadow-2xl transition-all flex-none';
                iframe.style.width = '390px';
                iframe.style.height = '844px';
                wrap.classList.add('p-6');
                mobileBtn.className = 'text-xs text-sky-400 transition-colors';
                desktopBtn.className = 'text-xs text-gray-600 hover:text-gray-400 transition-colors';
                tabletBtn.className = 'text-xs text-gray-600 hover:text-gray-400 transition-colors';
            } else if (vp === 'tablet') {
                iframe.className = 'border-0 bg-white rounded-2xl shadow-2xl transition-all flex-none';
                iframe.style.width = '834px';
                iframe.style.height = '1112px';
                wrap.classList.add('p-6');
                tabletBtn.className = 'text-xs text-sky-400 transition-colors';
                desktopBtn.className = 'text-xs text-gray-600 hover:text-gray-400 transition-colors';
                mobileBtn.className = 'text-xs text-gray-600 hover:text-gray-400 transition-colors';
            } else {
                iframe.className = 'w-full h-full border-0 bg-white';
                iframe.style.width = '';
                iframe.style.height = '';
                wrap.classList.remove('p-6');
                desktopBtn.className = 'text-xs text-sky-400 transition-colors';
                tabletBtn.className = 'text-xs text-gray-600 hover:text-gray-400 transition-colors';
                mobileBtn.className = 'text-xs text-gray-600 hover:text-gray-400 transition-colors';
            }
        }
    </script>
</body>
</html>
