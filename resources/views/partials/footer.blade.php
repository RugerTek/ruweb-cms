{{--
    Public footer (docs/layout-header-footer.md). The markup is code; the data
    comes from three places the client edits:
      - Footer screen: logo (shared site_logo), tagline, copyright line
      - Menus: the menu assigned to the `footer` location
      - Settings > Contacto: phone, WhatsApp, email, address and social links
    Variables arrive from the layouts.public view composer (AppServiceProvider).
--}}
@php
    $footerMenu = \App\Models\Menu::findByLocation('footer');
    $footerItems = $footerMenu?->tree() ?? collect();
    $hasContact = \App\Support\BusinessContact::hasAny();
    $socialLinks = \App\Support\BusinessContact::socialLinks();
    $copyright = strtr((string) ($footerCopyright ?? ''), ['{year}' => date('Y'), '{site}' => $siteName]);
    $footerHomeHref = app()->getLocale() === \App\Models\Locale::baseCode()
        ? route('home')
        : route('localized.home', ['locale' => app()->getLocale()]);
@endphp
<footer class="border-t border-gray-100 mt-auto bg-gray-50">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid gap-10 md:grid-cols-3">

            {{-- Brand: logo, tagline, socials --}}
            <div class="space-y-4">
                <a href="{{ $footerHomeHref }}" class="inline-flex items-center gap-2">
                    @if($siteLogoMedia ?? null)
                        <x-responsive-img :media="$siteLogoMedia" sizes="128px" :fallback-alt="$siteName" class="h-8 w-auto" />
                    @else
                        <span class="text-lg font-semibold text-gray-900">{{ $siteName }}</span>
                    @endif
                </a>
                @if(filled($footerTagline ?? null))
                    <div class="text-sm text-gray-600 leading-relaxed max-w-xs [&_a]:underline [&_a:hover]:text-gray-900" data-footer-tagline>{!! \App\Helpers\ContentHelper::richText($footerTagline) !!}</div>
                @endif
                @if(count($socialLinks))
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">{{ __('public.layout.follow_us') }}</p>
                        <x-social-links :links="$socialLinks" class="flex items-center gap-3 text-gray-500" icon-class="w-5 h-5" link-class="hover:text-gray-900 transition-colors" />
                    </div>
                @endif
            </div>

            {{-- Footer menu --}}
            @if($footerItems->isNotEmpty())
                <nav aria-label="{{ __('public.layout.links') }}">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">{{ __('public.layout.links') }}</p>
                    <x-menu-component location="footer"
                        class="space-y-2 [&_a]:text-sm [&_a]:text-gray-600 [&_a:hover]:text-gray-900 [&_a]:transition-colors [&_.sub-menu]:mt-2 [&_.sub-menu]:pl-3 [&_.sub-menu]:space-y-2" />
                </nav>
            @endif

            {{-- Contact --}}
            @if($hasContact)
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">{{ __('public.layout.contact') }}</p>
                    <ul class="space-y-2 text-sm text-gray-600">
                        @if($phone = \App\Support\BusinessContact::phone())
                            <li>
                                <a href="{{ \App\Support\BusinessContact::phoneHref() }}" class="hover:text-gray-900 transition-colors" title="{{ __('public.layout.call') }}">{{ $phone }}</a>
                            </li>
                        @endif
                        @if($whatsappHref = \App\Support\BusinessContact::whatsappHref())
                            <li>
                                <a href="{{ $whatsappHref }}" target="_blank" rel="noopener noreferrer" class="hover:text-gray-900 transition-colors">{{ __('public.layout.whatsapp') }}</a>
                            </li>
                        @endif
                        @if($email = \App\Support\BusinessContact::email())
                            <li>
                                <a href="{{ \App\Support\BusinessContact::emailHref() }}" class="hover:text-gray-900 transition-colors">{{ $email }}</a>
                            </li>
                        @endif
                        @if($address = \App\Support\BusinessContact::address())
                            <li class="whitespace-pre-line">{{ $address }}
                                @if($mapsHref = \App\Support\BusinessContact::mapsHref())
                                    <a href="{{ $mapsHref }}" target="_blank" rel="noopener noreferrer" class="block text-xs text-gray-500 hover:text-gray-900 transition-colors mt-1">{{ __('public.layout.view_map') }}</a>
                                @endif
                            </li>
                        @endif
                    </ul>
                </div>
            @endif
        </div>

        @if($copyright !== '' || filled($footerCredit ?? null))
            <div class="mt-10 pt-6 border-t border-gray-200 text-xs text-gray-500 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                @if($copyright !== '')
                    <p>{{ $copyright }}</p>
                @endif
                @if(filled($footerCredit ?? null))
                    <div class="[&_a]:underline [&_a:hover]:text-gray-900" data-footer-credit>{!! \App\Helpers\ContentHelper::richText($footerCredit) !!}</div>
                @endif
            </div>
        @endif
    </div>
</footer>
