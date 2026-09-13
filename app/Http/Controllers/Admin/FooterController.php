<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\PersistsSettings;
use App\Http\Controllers\Controller;
use App\Models\Locale;
use App\Models\Menu;
use App\Models\Setting;
use App\Support\BusinessContact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Footer screen: the footer's own fields plus shortcuts to the data it
 * displays from other modules. See docs/admin/layout.md.
 */
class FooterController extends Controller
{
    use PersistsSettings;

    public function index(): View
    {
        return view('admin.footer.index', [
            'logoSetting' => Setting::where('key', 'site_logo')->first(),
            'settings' => Setting::getGroup('footer')->keyBy('key'),
            'menu' => Menu::where('location', 'footer')->where('locale', Locale::baseCode())->first(),
            'contactSummary' => array_filter([BusinessContact::phone(), BusinessContact::email()]),
            'socialCount' => count(BusinessContact::socialLinks()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'settings.site_logo' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            ...$this->footerSettingRules(),
        ]);

        $this->persistSettings($request->input('settings', []));

        return redirect()
            ->route('admin.footer.index')
            ->with('success', __('admin.footer_saved'));
    }
}
