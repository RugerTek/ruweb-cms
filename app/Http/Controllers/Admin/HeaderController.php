<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\PersistsSettings;
use App\Http\Controllers\Controller;
use App\Models\Locale;
use App\Models\Menu;
use App\Models\Setting;
use App\Support\BusinessContact;
use App\Support\LinkDestination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Header screen: everything the client can change about the public header,
 * in one place. Fields the header owns are edited here; parts that live
 * elsewhere (menu, contact data) are shortcuts. See docs/admin/layout.md.
 */
class HeaderController extends Controller
{
    use PersistsSettings;

    public function index(): View
    {
        return view('admin.header.index', [
            'logoSetting' => Setting::where('key', 'site_logo')->first(),
            'settings' => Setting::getGroup('header')->keyBy('key'),
            'pages' => LinkDestination::pageOptions(),
            'menu' => Menu::where('location', 'header')->where('locale', Locale::baseCode())->first(),
            'contactSummary' => array_filter([BusinessContact::phone(), BusinessContact::email()]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'settings.site_logo' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            ...$this->headerSettingRules(),
        ]);

        $settings = $this->normalizeLinkDestination($request->input('settings', []), 'header_cta_page', 'header_cta_url');

        $this->persistSettings($settings);

        return redirect()
            ->route('admin.header.index')
            ->with('success', __('admin.header_saved'));
    }
}
