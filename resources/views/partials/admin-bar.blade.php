@unless($isEditorPreview ?? false)
@auth
@php
    $currentUrl = url()->full();
    $isAdmin = auth()->user()?->isAdmin();
    $adminLocale = \App\Models\Setting::get('admin_locale', 'es');
    $createLinks = \App\Support\AdminBar::createLinks(auth()->user(), $adminLocale, $currentUrl);
    $editHref = null;
    $editLabel = null;
    $editTitle = null;

    if (! app(\App\Support\AdminCapabilities::class)->liveEditor()) {
        $editHref = null;
    } elseif (request()->routeIs('blog.show') && isset($post)) {
        $editHref = route('admin.posts.edit', ['post' => $post->id, 'return' => $currentUrl]);
        $editLabel = trans('admin.action_edit', [], $adminLocale) . ' post';
        $editTitle = trans('admin.action_edit', [], $adminLocale) . ' este post';
    } elseif (request()->routeIs('page.show', 'home') && isset($page)) {
        $editHref = route('admin.pages.edit', ['page' => $page->id, 'return' => $currentUrl]);
        $editLabel = trans('admin.action_edit', [], $adminLocale) . ' p' . "\u{00E1}" . 'gina';
        $editTitle = trans('admin.action_edit', [], $adminLocale) . ' esta p' . "\u{00E1}" . 'gina';
    } else {
        $segments = request()->segments();
        $resourcePlural = $segments[0] ?? null;
        $resourceSingular = $resourcePlural ? \Illuminate\Support\Str::singular($resourcePlural) : null;
        $resourceLabel = $resourceSingular ? str_replace('-', ' ', $resourceSingular) : null;
        $resourceModel = $resourceSingular && array_key_exists($resourceSingular, get_defined_vars())
            ? get_defined_vars()[$resourceSingular]
            : null;

        if (
            $resourcePlural &&
            $resourceSingular &&
            is_object($resourceModel) &&
            isset($resourceModel->id) &&
            \Illuminate\Support\Facades\Route::has("admin.{$resourcePlural}.edit")
        ) {
            $editHref = route("admin.{$resourcePlural}.edit", [$resourceSingular => $resourceModel->id, 'return' => $currentUrl]);
            $editLabel = trans('admin.action_edit', [], $adminLocale) . ' ' . $resourceLabel;
            $editTitle = trans('admin.action_edit', [], $adminLocale) . ' este ' . $resourceLabel;
        }
    }
@endphp
<nav id="ruweb-admin-bar"
     class="fixed top-0 inset-x-0 z-[9999] h-9 bg-gray-900 flex items-center px-2 sm:px-4 shadow-sm">

    {{-- Left: actions --}}
    <div class="flex items-center min-w-0">

        {{-- Dashboard --}}
        <a href="{{ route('admin.dashboard') }}"
           class="flex items-center gap-1.5 px-2 py-1 rounded text-sky-400 hover:text-sky-300 hover:bg-white/5 transition-colors text-[11px] font-semibold tracking-wide flex-none"
           title="{{ trans('admin.dashboard', [], $adminLocale) }}">
            <svg class="w-3.5 h-3.5 flex-none" fill="currentColor" viewBox="0 0 20 20">
                <path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7A1 1 0 003 11h1v6a1 1 0 001 1h4v-4h2v4h4a1 1 0 001-1v-6h1a1 1 0 00.707-1.707l-7-7z"/>
            </svg>
            <span class="hidden sm:inline">Rüweb</span>
        </a>

        <span class="text-gray-700 mx-1 flex-none select-none">|</span>

        @if($editHref && $editLabel && $editTitle)
        <a href="{{ $editHref }}"
           class="flex items-center gap-1.5 px-2 py-1 rounded text-gray-400 hover:text-white hover:bg-white/10 transition-colors text-[11px] flex-none"
           title="{{ $editTitle }}">
            <svg class="w-3.5 h-3.5 flex-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
            </svg>
            <span class="hidden sm:inline">{{ $editLabel }}</span>
        </a>
        @endif

        <span class="text-gray-700 mx-1 flex-none select-none">|</span>

        @if(app(\App\Support\AdminCapabilities::class)->liveEditor())
        {{-- Menus --}}
        <a href="{{ route('admin.menus.index') }}"
           class="flex items-center gap-1.5 px-2 py-1 rounded text-gray-400 hover:text-white hover:bg-white/10 transition-colors text-[11px] flex-none"
           title="{{ trans('admin.action_edit', [], $adminLocale) . ' ' . trans('admin.menus', [], $adminLocale) }}">
            <svg class="w-3.5 h-3.5 flex-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
            </svg>
            <span class="hidden sm:inline">{{ trans('admin.menus', [], $adminLocale) }}</span>
        </a>

        @endif

        @if($createLinks)
        <span class="text-gray-700 mx-1 flex-none select-none">|</span>
        <details class="relative flex-none" data-admin-bar-create>
            <summary class="list-none [&::-webkit-details-marker]:hidden flex items-center gap-1.5 px-2 py-1 rounded text-gray-400 hover:text-white hover:bg-white/10 focus-visible:outline focus-visible:outline-sky-400 transition-colors text-[11px] cursor-pointer">
                <svg class="w-3.5 h-3.5 flex-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="M12 4v16m8-8H4"/>
                </svg>
                <span>{{ trans('admin.create_new_content', [], $adminLocale) }}</span>
                <svg class="w-3 h-3 flex-none opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </summary>
            <div class="absolute left-0 top-full pt-1 z-[10000]" data-admin-bar-create-menu>
                <div class="min-w-[10rem] rounded-md bg-gray-900 ring-1 ring-white/10 shadow-lg py-1">
                    @foreach($createLinks as $link)
                        <a href="{{ $link['href'] }}"
                           class="block px-3 py-1.5 text-[11px] text-gray-300 hover:text-white hover:bg-white/10 focus-visible:text-white focus-visible:bg-white/10 transition-colors">
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        </details>
        @endif

        @if($isAdmin)
        <span class="text-gray-700 mx-1 flex-none select-none">|</span>

        @if(app(\App\Support\AdminCapabilities::class)->liveEditor())
        {{-- Edit layout: hover / focus dropdown to the Header and Footer screens (docs/admin/layout.md). CSS only, no JS on the public side. --}}
        <div class="relative flex-none group">
            <button type="button"
                    class="flex items-center gap-1.5 px-2 py-1 rounded text-gray-400 hover:text-white hover:bg-white/10 group-hover:text-white group-hover:bg-white/10 group-focus-within:text-white group-focus-within:bg-white/10 transition-colors text-[11px] flex-none cursor-default"
                    aria-haspopup="true"
                    title="{{ trans('admin.edit_layout', [], $adminLocale) }}">
                <svg class="w-3.5 h-3.5 flex-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <rect x="3" y="4" width="18" height="16" rx="2"/>
                    <path stroke-linecap="round" d="M3 9h18M3 15h18"/>
                </svg>
                <span class="hidden sm:inline">{{ trans('admin.edit_layout', [], $adminLocale) }}</span>
                <svg class="w-3 h-3 flex-none opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div class="absolute left-0 top-full pt-1 hidden group-hover:block group-focus-within:block z-[10000]" data-admin-bar-layout-menu>
                <div class="min-w-[10rem] rounded-md bg-gray-900 ring-1 ring-white/10 shadow-lg py-1">
                    <a href="{{ route('admin.header.index') }}"
                       class="block px-3 py-1.5 text-[11px] text-gray-300 hover:text-white hover:bg-white/10 transition-colors">
                        {{ trans('admin.edit_header', [], $adminLocale) }}
                    </a>
                    <a href="{{ route('admin.footer.index') }}"
                       class="block px-3 py-1.5 text-[11px] text-gray-300 hover:text-white hover:bg-white/10 transition-colors">
                        {{ trans('admin.edit_footer', [], $adminLocale) }}
                    </a>
                </div>
            </div>
        </div>

        @endif
        <span class="text-gray-700 mx-1 flex-none select-none">|</span>

        <form method="POST"
              action="{{ route('admin.cache.refresh') }}"
              class="flex-none"
              onsubmit="return confirm('{{ trans('admin.cache_refresh_confirm', [], $adminLocale) }}')">
            @csrf
            <button type="submit"
                    class="flex items-center gap-1.5 px-2 py-1 rounded text-gray-400 hover:text-white hover:bg-white/10 transition-colors text-[11px] flex-none cursor-pointer"
                    title="{{ trans('admin.cache_refresh_title', [], $adminLocale) }}">
                <svg class="w-3.5 h-3.5 flex-none" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m14.356-2A8 8 0 005.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-13.357-2m13.357 2H15"/>
                </svg>
                <span class="hidden sm:inline">{{ trans('admin.cache_refresh', [], $adminLocale) }}</span>
            </button>
        </form>
        @endif
    </div>

    {{-- Right: user info --}}
    <div class="ml-auto flex items-center gap-1.5 text-[11px] text-gray-400 flex-none pl-2">
        <svg class="w-3.5 h-3.5 flex-none" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/>
        </svg>
        <span class="hidden sm:inline truncate max-w-[120px]">{{ auth()->user()->name }}</span>
    </div>

</nav>
@endauth

@endunless
