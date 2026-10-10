<?php

namespace App\Services\Settings;

use App\Exceptions\RevisionConflict;
use App\Http\Controllers\Admin\Concerns\PersistsSettings;
use App\Models\Locale;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Services\Editorial\EditorialActor;
use App\Services\Revisions\ContentRevisionLedger;
use App\Support\AdminLoginPath;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** Shared typed persistence; MCP additionally enforces the reviewed public settings allowlist. */
final class SettingOperations
{
    use PersistsSettings;

    public function fingerprint(Setting $setting): string
    {
        return hash('sha256', json_encode([$setting->attributesToArray(), $setting->translations()->orderBy('locale')->get()->toArray()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function persist(User $actor, array $settings): void
    {
        abort_unless($actor->isAdmin(), 403);
        EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($settings) {
            foreach ($settings as $key => $value) {
                $existing = Setting::where('key', $key)->first();

                if (! $existing) {
                    continue;
                }

                if ($existing->type === 'password' && ($value === null || $value === '')) {
                    continue;
                }

                $value = match ($existing->type) {
                    'social_links' => $this->normalizeSocialLinks($value),
                    'map_point' => $this->normalizeMapPoint($value),
                    'rich_text' => $this->normalizeRichText($value),
                    default => $value,
                };

                Setting::set($key, $value);
            }

            Setting::clearCache();
        }, 3));
    }

    public function update(User $actor, array $settings, ?string $locale = null, array $revisions = []): void
    {
        abort_unless($actor->isAdmin(), 403);
        Validator::make(['locale' => $locale, 'settings' => $settings], ['locale' => ['nullable', Rule::in(Locale::installedCodes())], 'settings' => ['required', 'array', 'max:50']])->validate();
        foreach (array_keys($settings) as $key) {
            abort_unless(in_array($key, config('cms.mcp.settings_writable_keys', []), true), 422, 'Setting key is read-only in MCP.');
        }
        Validator::make(['settings' => $settings], $this->contactSettingRules() + $this->headerSettingRules() + $this->footerSettingRules() + [
            'settings.site_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.site_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'settings.site_logo' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'settings.site_favicon' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'settings.default_social_image' => ['sometimes', 'nullable', 'integer', 'exists:media,id'],
            'settings.admin_login_path' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/', function ($attribute, $value, $fail) {
                $raw = strtolower(trim((string) $value));
                $normalized = AdminLoginPath::normalize($raw);
                if ($raw !== '' && (($normalized === AdminLoginPath::DEFAULT_SEGMENT && $raw !== $normalized) || in_array($normalized, Locale::catalogCodes(), true) || Page::where('locale', Locale::baseCode())->where('slug', $normalized)->exists())) {
                    $fail(__('validation.not_in'));
                }
            }],
        ])->validate();
        if (array_key_exists('admin_login_path', $settings)) {
            $settings['admin_login_path'] = AdminLoginPath::normalize($settings['admin_login_path']);
        }
        if (array_key_exists('header_cta_url', $settings) && ! array_key_exists('header_cta_page', $settings)) {
            $settings['header_cta_page'] = null;
        }
        if (array_key_exists('header_cta_page', $settings) || array_key_exists('header_cta_url', $settings)) {
            $settings = $this->normalizeLinkDestination($settings, 'header_cta_page', 'header_cta_url');
        }
        try {
            EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($actor, $settings, $locale, $revisions) {
                foreach ($settings as $key => $value) {
                    $setting = Setting::where('key', $key)->lockForUpdate()->first();
                    abort_unless($setting, 404, 'Setting not found.');
                    if (isset($revisions[$key])) {
                        $current = $this->fingerprint($setting);
                        if (! hash_equals($current, $revisions[$key])) {
                            throw new RevisionConflict($revisions[$key], $current);
                        }
                    }
                    if ($locale !== null && $locale !== Locale::baseCode()) {
                        abort_unless(in_array($key, ['site_name', 'site_description', 'site_logo', 'default_social_image', 'header_cta_label', 'footer_tagline', 'footer_copyright', 'footer_credit'], true), 422, 'This setting is global and does not accept localized values.');
                        $value = $setting->type === 'rich_text' ? $this->normalizeRichText($value) : $value;
                        Setting::setLocalized($key, $locale, $value);
                    } else {
                        $this->persist($actor, [$key => $value]);
                    }
                }
            }, 3));
        } finally {
            Setting::clearCache();
        }
    }
}
