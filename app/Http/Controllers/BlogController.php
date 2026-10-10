<?php

namespace App\Http\Controllers;

use App\Models\Locale;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class BlogController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $locale = $request->route('locale');
        if (is_string($locale) && ($locale === Locale::baseCode() || ! Locale::secondaryPublicCodes()->contains($locale))) {
            return redirect('/blog', 302);
        }

        $query = Post::published()
            ->where('locale', app()->getLocale())
            ->latest('published_at');

        if ($request->filled('category')) {
            $categorySlug = $request->string('category')->toString();
            $query->whereHas('taxonomies', function ($q) use ($categorySlug) {
                $q->where('type', 'category')
                    ->where('locale', app()->getLocale())
                    ->where('slug', $categorySlug);
            });
        }

        $request->attributes->set('custom_code_target', ['type' => 'indexes', 'id' => 'blog', 'locale' => app()->getLocale()]);
        return view('blog.index', [
            'posts' => $query->paginate(9)->withQueryString(),
        ]);
    }

    public function show(Request $request): View|RedirectResponse
    {
        $slug = (string) $request->route('slug');
        $locale = $request->route('locale');

        if (is_string($locale) && ($locale === Locale::baseCode() || ! Locale::secondaryPublicCodes()->contains($locale))) {
            return redirect('/blog/'.$slug, 302);
        }

        $post = Post::published()
            ->where('locale', app()->getLocale())
            ->where('slug', $slug)
            ->first();

        if (! $post && app()->getLocale() !== Locale::baseCode()) {
            $base = Post::published()
                ->where('locale', Locale::baseCode())
                ->where('slug', $slug)
                ->first();

            return redirect($base?->url() ?: '/blog', 302);
        }

        abort_if(! $post, 404);

        $request->attributes->set('custom_code_target', app(\App\Support\CustomCodeDestinations::class)->forModel($post));
        return view('blog.show', [
            'post' => $post,
            'page' => $post,
        ]);
    }
}
