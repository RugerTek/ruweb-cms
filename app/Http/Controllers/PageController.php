<?php

namespace App\Http\Controllers;

use App\Models\Locale;
use App\Models\Page;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function home(Request $request): View|RedirectResponse
    {
        $locale = $request->route('locale');
        if ($redirect = $this->redirectIfInvalidLocale($locale)) {
            return $redirect;
        }

        $homepageGroupId = Setting::get('homepage_translation_group_id');
        $page = $homepageGroupId
            ? Page::where('translation_group_id', $homepageGroupId)->where('locale', app()->getLocale())->published()->first()
            : null;

        if (! $page) {
            if (app()->getLocale() !== Locale::baseCode()) {
                return redirect('/', 302);
            }

            return view('welcome');
        }

        $request->attributes->set('custom_code_target', app(\App\Support\CustomCodeDestinations::class)->forModel($page));
        return view($page->resolveTemplate(), compact('page'));
    }

    public function show(Request $request): View|RedirectResponse
    {
        $locale = $request->route('locale');
        $slug = (string) $request->route('slug');

        if ($redirect = $this->redirectIfInvalidLocale($locale)) {
            return $redirect;
        }

        $page = Page::where('slug', $slug)
            ->where('locale', app()->getLocale())
            ->published()
            ->first();

        if (! $page && app()->getLocale() !== Locale::baseCode()) {
            $candidate = Page::where('slug', $slug)
                ->where('locale', app()->getLocale())
                ->first();

            $base = $candidate?->baseTranslation();

            $base ??= Page::where('slug', $slug)
                ->where('locale', Locale::baseCode())
                ->published()
                ->first();

            return redirect($base?->url() ?: '/', 302);
        }

        abort_if(! $page, 404);

        $request->attributes->set('custom_code_target', app(\App\Support\CustomCodeDestinations::class)->forModel($page));
        return view($page->resolveTemplate(), compact('page'));
    }

    private function redirectIfInvalidLocale(?string $locale): ?RedirectResponse
    {
        if (! $locale) {
            return null;
        }

        if ($locale === Locale::baseCode() || ! Locale::secondaryPublicCodes()->contains($locale)) {
            return redirect(request()->path() === $locale ? '/' : '/'.trim(preg_replace('#^'.preg_quote($locale, '#').'/?#', '', request()->path()), '/'), 302);
        }

        return null;
    }
}
