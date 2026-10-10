<?php

namespace App\Http\Controllers;

use App\Models\Locale;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        // Publication and expiry are time-dependent, including scheduled content.
        // Resolve current visibility per request rather than serving stale cached URLs.
        $xml = (function () {
            $homepageGroupId = Setting::get('homepage_translation_group_id');
            $homePage = $homepageGroupId
                ? Page::published()->where('translation_group_id', $homepageGroupId)->where('locale', Locale::baseCode())->first()
                : null;
            $pages = Page::published()
                ->whereIn('locale', Locale::publicCodes())
                ->when($homepageGroupId, fn ($query) => $query->where('translation_group_id', '!=', $homepageGroupId))
                ->get();
            $posts = Post::published()
                ->whereIn('locale', Locale::publicCodes())
                ->latest('published_at')
                ->get();
            $latestPostDate = $posts->first()?->updated_at;

            return view('sitemap.index', compact('homePage', 'pages', 'posts', 'latestPostDate'))->render();
        })();

        return response($xml, 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }
}
