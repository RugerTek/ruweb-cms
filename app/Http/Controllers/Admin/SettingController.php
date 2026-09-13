<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Locale;
use App\Models\Page;
use App\Models\Setting;
use App\Support\AdminLoginPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingController extends Controller
{
    public function index(): View
    {
        $this->ensureAdminLoginPathSettingExists();

        $groupOrder = ['general', 'admin', 'security'];
        $groups = Setting::allGrouped()
            ->filter(fn ($v, $k) => ! in_array($k, ['analytics', 'email']))
            ->sortBy(fn ($v, $k) => array_search($k, $groupOrder) !== false ? array_search($k, $groupOrder) : 99);
        $homepageOptions = Page::published()
            ->where('locale', Locale::baseCode())
            ->orderBy('title')
            ->get(['title', 'slug', 'translation_group_id'])
            ->mapWithKeys(fn (Page $page) => [$page->translation_group_id => $page->title . ' (/' . ltrim($page->slug, '/') . ')'])
            ->all();

        $homepageSetting = $groups->get('general')?->firstWhere('key', 'homepage_translation_group_id');
        if ($homepageSetting) {
            if (empty($homepageOptions)) {
                $homepageOptions[$homepageSetting->value] = __('admin.settings_fields.homepage_translation_group_id.empty');
            } elseif (! empty($homepageSetting->value) && ! array_key_exists($homepageSetting->value, $homepageOptions)) {
                $homepageOptions[$homepageSetting->value] = __('admin.settings_fields.homepage_translation_group_id.unpublished');
            }

            $homepageSetting->options = $homepageOptions;
        }

        return view('admin.settings.index', [
            'groups' => $groups,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $allowedHomepageGroups = Page::published()
            ->where('locale', Locale::baseCode())
            ->pluck('translation_group_id')
            ->all();
        $allowedHomepageGroups[] = Setting::get('homepage_translation_group_id');
        $allowedHomepageGroups = array_values(array_unique(array_filter($allowedHomepageGroups)));

        $request->validate([
            'settings.homepage_translation_group_id' => ['sometimes', 'string', Rule::in($allowedHomepageGroups)],
            'settings.admin_login_path' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                'regex:/^[A-Za-z0-9-]+$/',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $raw = trim((string) $value);

                    if ($raw === '') {
                        return;
                    }

                    $normalized = AdminLoginPath::normalize($raw);
                    $rawLower = strtolower(trim($raw, '/'));

                    if ($normalized === AdminLoginPath::DEFAULT_SEGMENT && $rawLower !== AdminLoginPath::DEFAULT_SEGMENT) {
                        $fail(__('validation.not_in'));
                        return;
                    }

                    if (in_array($normalized, Locale::catalogCodes(), true)) {
                        $fail(__('validation.not_in'));
                        return;
                    }

                    $collidesWithPage = Page::query()
                        ->where('locale', Locale::baseCode())
                        ->where('slug', $normalized)
                        ->exists();

                    if ($collidesWithPage) {
                        $fail(__('validation.not_in'));
                    }
                },
            ],
        ]);

        $settings = $request->input('settings', []);

        if (array_key_exists('admin_login_path', $settings)) {
            $settings['admin_login_path'] = AdminLoginPath::normalize($settings['admin_login_path']);
        }

        foreach ($settings as $key => $value) {
            // Don't overwrite password-type settings when field is left blank.
            $existing = Setting::where('key', $key)->first();

            if ($key === 'admin_login_path' && ! $existing) {
                Setting::create([
                    'key' => 'admin_login_path',
                    'value' => $value,
                    'type' => 'string',
                    'group' => 'admin',
                    'options' => null,
                ]);

                continue;
            }

            if ($existing && $existing->type === 'password' && ($value === null || $value === '')) {
                continue;
            }
            Setting::set($key, $value);
        }

        Setting::clearCache();

        if (array_key_exists('admin_login_path', $settings)) {
            AdminLoginPath::clearCache();

            if (app()->routesAreCached()) {
                // routes/web.php compiles the login route from this setting, so a
                // stale route cache would keep serving the old path. Rebuild it; if
                // that fails, a cleared cache still resolves routes dynamically.
                try {
                    Artisan::call('route:cache');
                } catch (\Throwable) {
                    Artisan::call('route:clear');
                }
            }
        }

        return redirect()
            ->route('admin.settings.index')
            ->with('success', __('admin.settings_saved'));
    }

    private function ensureAdminLoginPathSettingExists(): void
    {
        if (Setting::where('key', 'admin_login_path')->exists()) {
            return;
        }

        Setting::create([
            'key' => 'admin_login_path',
            'value' => AdminLoginPath::DEFAULT_SEGMENT,
            'type' => 'string',
            'group' => 'admin',
            'options' => null,
        ]);

        Setting::clearCache();
        AdminLoginPath::clearCache();
    }
}
