<aside id="sidebar" class="fixed top-0 left-0 z-50 w-56 h-screen bg-[#111111] border-r border-white/[0.06] flex flex-col transition-transform -translate-x-full lg:translate-x-0">

    {{-- Logo --}}
    <a href="{{ route('home') }}"
       target="_blank"
       rel="noopener noreferrer"
       class="flex items-center gap-2.5 px-4 h-14 border-b border-white/[0.06] shrink-0 hover:bg-white/[0.02] transition-colors">
        <span class="w-2 h-2 rounded-full bg-sky-500 shrink-0"></span>
        <span class="text-sm font-semibold text-white tracking-tight">{{ $siteName }}</span>
    </a>

    {{-- Navigation --}}
    <nav class="flex-1 overflow-y-auto py-3 px-2">
        <ul class="space-y-0.5">
            <li>
                <a href="{{ route('admin.dashboard') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.dashboard')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    <span>{{ __('admin.dashboard') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('admin.pages.index') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.pages.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span>{{ __('admin.pages') }}</span>
                </a>
            </li>
            @php
                $formRegistry = app(\App\Services\Forms\FormRegistry::class);
                $firstFormKey = $formRegistry->firstKey();
                $unreadSubmissions = $firstFormKey ? $formRegistry->unreadTotal() : 0;
            @endphp
            @if($firstFormKey)
                <li>
                    <a href="{{ route('admin.submissions.index', ['form' => $firstFormKey]) }}"
                       class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                              {{ request()->routeIs('admin.submissions.*')
                                  ? 'bg-sky-500/10 text-sky-400'
                                  : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <span class="flex-1">{{ __('admin.submissions') }}</span>
                        @if($unreadSubmissions > 0)
                            <span class="inline-flex items-center justify-center min-w-[1.25rem] px-1.5 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 text-xs">{{ $unreadSubmissions }}</span>
                        @endif
                    </a>
                </li>
            @endif
            @if(app(\App\Support\AdminCapabilities::class)->liveEditor())
            @php
                $postsGroupActive = request()->routeIs('admin.posts.*') || request()->routeIs('admin.taxonomies.*');
            @endphp
            <li>
                <button type="button" data-sidebar-toggle="posts-group"
                        class="w-full flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                               {{ $postsGroupActive ? 'text-gray-300' : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>
                    </svg>
                    <span class="flex-1 text-left">{{ __('admin.posts') }}</span>
                    <svg data-sidebar-chevron="posts-group"
                         class="w-3 h-3 shrink-0 transition-transform duration-200 {{ $postsGroupActive ? 'rotate-180' : '' }}"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <ul id="posts-group"
                    class="mt-0.5 ml-3 pl-3 border-l border-white/[0.06] space-y-0.5 {{ $postsGroupActive ? '' : 'hidden' }}">
                    <li>
                        <a href="{{ route('admin.posts.index') }}"
                           class="flex items-center px-2 py-1.5 rounded-md text-xs transition-colors
                                  {{ request()->routeIs('admin.posts.*') ? 'text-sky-400' : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                            {{ __('admin.all_posts') }}
                        </a>
                    </li>
                    @if(app(\App\Support\AdminCapabilities::class)->liveEditor())
                    <li>
                        <a href="{{ route('admin.taxonomies.index', 'category') }}"
                           class="flex items-center px-2 py-1.5 rounded-md text-xs transition-colors
                                  {{ request()->routeIs('admin.taxonomies.*') ? 'text-sky-400' : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                            {{ __('admin.categories') }}
                        </a>
                    </li>
                    @endif
                </ul>
            </li>
            @else
                @foreach(config('cms.collections', []) as $collectionKey => $collection)
                    @if(\Illuminate\Support\Facades\Gate::allows('viewAny', $collection['model']))
                        <li>
                            <a href="{{ route('admin.content.index', $collectionKey) }}"
                               class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors {{ request()->routeIs('admin.content.*') && request()->route('type') === $collectionKey ? 'bg-sky-500/10 text-sky-400' : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2"/>
                                </svg>
                                <span>{{ __($collection['label']) }}</span>
                            </a>
                        </li>
                    @endif
                @endforeach
            @endif
            @if(app(\App\Support\AdminCapabilities::class)->liveEditor())
            <li>
                <a href="{{ route('admin.menus.index') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.menus.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                    <span>{{ __('admin.menus') }}</span>
                </a>
            </li>
            @if(auth()->user()->isAdmin())
            {{-- Header / Footer: one screen each centralising the editable parts of the layout (docs/admin/layout.md) --}}
            <li>
                <a href="{{ route('admin.header.index') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.header.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <rect x="3" y="4" width="18" height="16" rx="2" stroke-width="2"/>
                        <path stroke-linecap="round" stroke-width="2" d="M3 9h18"/>
                    </svg>
                    <span>{{ __('admin.header') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('admin.footer.index') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.footer.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <rect x="3" y="4" width="18" height="16" rx="2" stroke-width="2"/>
                        <path stroke-linecap="round" stroke-width="2" d="M3 15h18"/>
                    </svg>
                    <span>{{ __('admin.footer') }}</span>
                </a>
            </li>
            @endif
            @endif
            <li>
                <a href="{{ route('admin.editorial-control.index') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.editorial-control.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7 4h10a2 2 0 012 2v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6a2 2 0 012-2z"/>
                    </svg>
                    <span>{{ __('admin.editorial_control') }}</span>
                </a>
            </li>
            @php
                $mediaGroupActive = request()->routeIs('admin.media.*');
            @endphp
            <li>
                <button type="button" data-sidebar-toggle="media-group"
                        class="w-full flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                               {{ $mediaGroupActive ? 'text-gray-300' : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span class="flex-1 text-left">{{ __('admin.media') }}</span>
                    <svg data-sidebar-chevron="media-group"
                         class="w-3 h-3 shrink-0 transition-transform duration-200 {{ $mediaGroupActive ? 'rotate-180' : '' }}"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <ul id="media-group"
                    class="mt-0.5 ml-3 pl-3 border-l border-white/[0.06] space-y-0.5 {{ $mediaGroupActive ? '' : 'hidden' }}">
                    <li>
                        <a href="{{ route('admin.media.index') }}"
                           class="flex items-center px-2 py-1.5 rounded-md text-xs transition-colors
                                  {{ request()->routeIs('admin.media.*') && !request()->routeIs('admin.media.health') ? 'text-sky-400' : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                            {{ __('admin.media') }}
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('admin.media.health') }}"
                           class="flex items-center px-2 py-1.5 rounded-md text-xs transition-colors
                                  {{ request()->routeIs('admin.media.health') ? 'text-sky-400' : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                            {{ __('admin.media_health') }}
                        </a>
                    </li>
                </ul>
            </li>
            @if(auth()->user()->isAdmin())
            <li>
                <a href="{{ route('admin.settings.index', ['tab' => 'general']) }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.settings.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span>{{ __('admin.settings') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('admin.languages.index') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.languages.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 8h7M11 21l5-10 5 10m-8.5-3h7"/>
                    </svg>
                    <span>{{ __('admin.languages') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('admin.analytics.index') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.analytics.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M7 16v-4M11 16V8M15 16v-7M19 16V5"/>
                    </svg>
                    <span>{{ __('admin.analytics') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('admin.email.index') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.email.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <span>{{ __('admin.email') }}</span>
                </a>
            </li>
            <li>
                <a href="{{ route('admin.users.index') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.users.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-4-4h-1M9 20H4v-2a4 4 0 014-4h1m8-4a4 4 0 11-8 0 4 4 0 018 0zm6 2a3 3 0 11-6 0 3 3 0 016 0zM9 8a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    <span>{{ __('admin.users') }}</span>
                </a>
            </li>
            @endif
            <li>
                <a href="{{ route('admin.claude-mcp.index') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.claude-mcp.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span>{{ __('admin.claude_mcp') }}</span>
                </a>
            </li>
            @if(auth()->user()->isAdmin())
            <li>
                <a href="{{ route('admin.developer-tools.index') }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ request()->routeIs('admin.developer-tools.*')
                              ? 'bg-sky-500/10 text-sky-400'
                              : 'text-gray-500 hover:text-gray-300 hover:bg-white/[0.04]' }}">
                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.7 6.3a4 4 0 015-1.1l-3.1 3.1 2.1 2.1 3.1-3.1a4 4 0 01-5.1 5.1L8.4 20.7a2 2 0 01-2.8-2.8l8.3-8.3a4 4 0 01.8-3.3z"/>
                    </svg>
                    <span>{{ __('admin.developer_tools') }}</span>
                </a>
            </li>
            @endif
        </ul>
    </nav>

    {{-- User --}}
    <div class="shrink-0 px-3 py-3 border-t border-white/[0.06]">
        @php
            $roleKey = 'admin.role_' . auth()->user()->role;
            $roleLabel = __($roleKey) !== $roleKey ? __($roleKey) : ucfirst(auth()->user()->role);
        @endphp
        <div class="flex items-center justify-between gap-2">
            <div class="min-w-0">
                <a href="{{ route('admin.profile.index') }}"
                   class="block text-xs font-medium text-gray-300 truncate hover:text-white transition-colors {{ request()->routeIs('admin.profile.*') ? 'text-sky-400' : '' }}">
                    {{ auth()->user()->name }}
                </a>
                <p class="text-xs text-gray-600 capitalize">{{ $roleLabel }}</p>
            </div>
            <form method="POST" action="{{ route('admin.logout') }}" class="shrink-0">
                @csrf
                <button type="submit" title="{{ __('admin.logout') }}" class="text-gray-600 hover:text-gray-400 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</aside>

{{-- Backdrop (mobile) --}}
<div id="sidebar-backdrop" class="lg:hidden hidden fixed inset-0 z-40 bg-black/50" onclick="document.getElementById('sidebar').classList.add('-translate-x-full'); this.classList.add('hidden');"></div>

@push('scripts')
<script>
    document.getElementById('sidebar-toggle')?.addEventListener('click', () => {
        document.getElementById('sidebar-backdrop').classList.remove('hidden');
    });

    document.querySelectorAll('[data-sidebar-toggle]').forEach(btn => {
        btn.addEventListener('click', () => {
            const groupId = btn.dataset.sidebarToggle;
            const group   = document.getElementById(groupId);
            const chevron = document.querySelector('[data-sidebar-chevron="' + groupId + '"]');
            const isOpen  = !group.classList.contains('hidden');
            group.classList.toggle('hidden', isOpen);
            chevron?.classList.toggle('rotate-180', !isOpen);
        });
    });
</script>
@endpush
