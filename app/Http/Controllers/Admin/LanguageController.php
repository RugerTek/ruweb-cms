<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Locale;
use App\Services\Content\LocaleOperations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LanguageController extends Controller
{
    public function index(): View
    {
        $locales = Locale::orderBy('sort_order')->get();

        $available = collect(Locale::catalog())
            ->reject(fn (string $name, string $code): bool => $locales->contains('code', $code))
            ->all();

        return view('admin.languages.index', compact('locales', 'available'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', Rule::unique('locales', 'code')]]);
        app(LocaleOperations::class)->install($request->user(), (string) $request->input('code'));

        return redirect()->route('admin.languages.index')->with('success', __('admin.language_added'));
    }

    public function update(Request $request): RedirectResponse
    {
        app(LocaleOperations::class)->update($request->user(), $request->input('locales', []), replace: true);

        return redirect()->route('admin.languages.index')->with('success', __('admin.settings_saved'));
    }
}
