@php
    use App\Contracts\Editorial\Seoable;
    use App\Helpers\ContentHelper;
    use App\Models\Locale;
    use App\Models\Setting;

    $entity = $seoable ?? $page ?? null;
    $entity = $entity instanceof Seoable ? $entity : null;
    $meta = $entity ? $entity->meta() : [];
    $siteName = Setting::getLocalized('site_name') ?: config('app.name');
    $faviconSetting = Setting::get('site_favicon');
    $googleTagId = Setting::get('google_tag_id');
    $metaPixelId = Setting::get('meta_pixel_id');
    $searchConsoleVerificationToken = Setting::get('search_console_verification_token');
    $favicon = is_string($faviconSetting)
        ? $faviconSetting
        : $faviconSetting?->url();
    $favicon = $favicon ?: '/favicon.ico';
    $faviconType = is_string($faviconSetting)
        ? match (strtolower(pathinfo(parse_url($favicon, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            default => 'image/x-icon',
        }
        : ($faviconSetting?->mime_type ?: 'image/x-icon');

    $title = ContentHelper::metaTitle($meta, $entity?->seoTitleFallback());
    $description = ContentHelper::metaDescription($meta);
    $image = ContentHelper::metaImage($meta);
    $url = $entity ? url($entity->url()) : url()->current();
    $alternates = collect();
    if ($entity && method_exists($entity, 'availablePublishedTranslations')) {
        $alternates = $entity->availablePublishedTranslations();
    }
    $baseAlternate = $alternates->firstWhere('locale', Locale::baseCode());
@endphp

<title>{{ $title }}{{ $title !== $siteName ? ' — ' . $siteName : '' }}</title>

@if($favicon)
<link rel="icon" type="{{ $faviconType }}" href="{{ $favicon }}">
@endif

@if($description)
<meta name="description" content="{{ $description }}">
@endif

@if($searchConsoleVerificationToken)
<meta name="google-site-verification" content="{{ $searchConsoleVerificationToken }}">
@endif

<link rel="canonical" href="{{ $url }}">
@foreach($alternates as $alternate)
<link rel="alternate" hreflang="{{ $alternate->locale }}" href="{{ url($alternate->url()) }}">
@endforeach
@if($baseAlternate)
<link rel="alternate" hreflang="x-default" href="{{ url($baseAlternate->url()) }}">
@endif

{{-- Open Graph --}}
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $title }}">
<meta property="og:url" content="{{ $url }}">
<meta property="og:site_name" content="{{ $siteName }}">
@if($description)
<meta property="og:description" content="{{ $description }}">
@endif
@if($image)
<meta property="og:image" content="{{ $image }}">
<meta property="og:image:secure_url" content="{{ $image }}">
<meta property="og:image:alt" content="{{ $title }}">
@endif

{{-- Twitter Card --}}
<meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $title }}">
@if($description)
<meta name="twitter:description" content="{{ $description }}">
@endif
@if($image)
<meta name="twitter:image" content="{{ $image }}">
<meta name="twitter:image:alt" content="{{ $title }}">
@endif

@if($googleTagId && ! ($isEditorPreview ?? false))
<script async src="https://www.googletagmanager.com/gtag/js?id={{ urlencode($googleTagId) }}"></script>
<script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', '{{ $googleTagId }}');
</script>
@endif

@if($metaPixelId && ! ($isEditorPreview ?? false))
<script>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', '{{ $metaPixelId }}');
    fbq('track', 'PageView');
</script>
@endif
