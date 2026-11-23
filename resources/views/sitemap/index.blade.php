{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">

    {{-- Homepage --}}
    <url>
        <loc>{{ url('/') }}</loc>
        @if($homePage)
            @foreach($homePage->availablePublishedTranslations() as $alternate)
        <xhtml:link rel="alternate" hreflang="{{ $alternate->locale }}" href="{{ url($alternate->url()) }}" />
            @endforeach
        @endif
        @if($homePage)
        <lastmod>{{ $homePage->updated_at->toW3cString() }}</lastmod>
        @endif
        <changefreq>daily</changefreq>
        <priority>1.0</priority>
    </url>

    {{-- Static pages --}}
    @foreach($pages as $page)
    <url>
        <loc>{{ url($page->url()) }}</loc>
        @foreach($page->availablePublishedTranslations() as $alternate)
        <xhtml:link rel="alternate" hreflang="{{ $alternate->locale }}" href="{{ url($alternate->url()) }}" />
        @endforeach
        <lastmod>{{ $page->updated_at->toW3cString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.8</priority>
    </url>
    @endforeach

    {{-- Blog index --}}
    <url>
        <loc>{{ url('/blog') }}</loc>
        @if($latestPostDate)
        <lastmod>{{ $latestPostDate->toW3cString() }}</lastmod>
        @endif
        <changefreq>daily</changefreq>
        <priority>0.6</priority>
    </url>

    {{-- Blog posts --}}
    @foreach($posts as $post)
    <url>
        <loc>{{ url($post->url()) }}</loc>
        @foreach($post->availablePublishedTranslations() as $alternate)
        <xhtml:link rel="alternate" hreflang="{{ $alternate->locale }}" href="{{ url($alternate->url()) }}" />
        @endforeach
        <lastmod>{{ $post->updated_at->toW3cString() }}</lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.5</priority>
    </url>
    @endforeach

    @if($shopPath)
    {{-- Shop catalog index (optional module) --}}
    <url>
        <loc>{{ url($shopPath) }}</loc>
        @if($latestProductDate)
        <lastmod>{{ $latestProductDate->toW3cString() }}</lastmod>
        @endif
        <changefreq>daily</changefreq>
        <priority>0.6</priority>
    </url>

    {{-- Products --}}
    @foreach($products as $product)
    <url>
        <loc>{{ url($product->url()) }}</loc>
        @foreach($product->availablePublishedTranslations() as $alternate)
        <xhtml:link rel="alternate" hreflang="{{ $alternate->locale }}" href="{{ url($alternate->url()) }}" />
        @endforeach
        <lastmod>{{ $product->updated_at->toW3cString() }}</lastmod>
        <changefreq>weekly</changefreq>
        <priority>0.5</priority>
    </url>
    @endforeach
    @endif

</urlset>
