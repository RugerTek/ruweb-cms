{{--
    Meta Pixel connector (App\Services\Marketing\MetaPixel, docs/operations/meta-pixel.md), included in the <head> of
    layouts.public. Renders nothing while the connector is off, inside the panel's previews, for a signed-in panel user
    or without consent (MetaPixel::resolveConsentUsing()). Otherwise loads Meta's script and sends this page's PageView
    and the events collected while rendering it (ViewContent, InitiateCheckout, Purchase) or flashed by the previous
    POST (AddToCart, Lead), each with the event_id its Conversions API twin carries.
    Receives $isEditorPreview.
--}}
@php
    $metaPixel = app(\App\Services\Marketing\MetaPixel::class);
    $metaPixelId = $metaPixel->tracks(request(), (bool) ($isEditorPreview ?? false)) ? $metaPixel->pixelId() : null;
    $metaPixelPageView = $metaPixelId !== null ? $metaPixel->pageView() : null;
    $metaPixelEvents = $metaPixelId !== null ? $metaPixel->takeBrowserEvents() : [];
@endphp
@if($metaPixelId !== null)
<script data-meta-pixel>
    !function(f,b,e,v,n,t,s)
    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
    n.queue=[];t=b.createElement(e);t.async=!0;
    t.src=v;s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)}(window, document,'script',
    'https://connect.facebook.net/en_US/fbevents.js');
    fbq('init', {{ \Illuminate\Support\Js::from($metaPixelId) }});
@foreach($metaPixelEvents as $metaPixelEvent)
    fbq('track', {{ \Illuminate\Support\Js::from($metaPixelEvent['name']) }}, {{ \Illuminate\Support\Js::from((object) $metaPixelEvent['data']) }}, {eventID: {{ \Illuminate\Support\Js::from($metaPixelEvent['id']) }}});
@endforeach
</script>
<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id={{ urlencode($metaPixelId) }}&amp;ev=PageView&amp;noscript=1{{ $metaPixelPageView ? '&eid='.urlencode($metaPixelPageView) : '' }}"></noscript>
@endif
