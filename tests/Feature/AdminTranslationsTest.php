<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Tests\TestCase;

class AdminTranslationsTest extends TestCase
{
    public function test_all_spanish_and_english_namespaces_have_identical_keys(): void
    {
        $spanish = glob(lang_path('es/*.php'));
        $english = glob(lang_path('en/*.php'));
        $this->assertSame(array_map('basename', $spanish), array_map('basename', $english));
        foreach ($spanish as $file) {
            $es = array_keys(Arr::dot(require $file));
            $en = array_keys(Arr::dot(require lang_path('en/'.basename($file))));
            sort($es);
            sort($en);
            $this->assertSame($es, $en, basename($file));
        }
    }

    public function test_homepage_settings_translation_keys_exist_in_spanish_and_english(): void
    {
        $keys = [
            'admin.settings_fields.homepage_translation_group_id.label',
            'admin.settings_fields.homepage_translation_group_id.help',
            'admin.settings_fields.homepage_translation_group_id.empty',
            'admin.settings_fields.homepage_translation_group_id.unpublished',
            'admin.analytics',
            'admin.analytics_subtitle',
            'admin.analytics_google_title',
            'admin.analytics_meta_title',
            'admin.analytics_search_console_title',
            'admin.analytics_validation.google_tag_id',
            'admin.analytics_validation.meta_pixel_id',
            'admin.analytics_validation.search_console_verification_token',
            'admin.field_seo_title',
            'admin.field_seo_description',
            'admin.editorial_translations_title',
            'admin.editorial_content_tab',
            'admin.editorial_problem',
            'admin.editorial_col_content',
            'admin.editorial_completeness_title',
            'admin.dashboard_translation_coverage_title',
        ];

        foreach ($keys as $key) {
            $this->assertTrue(trans()->has($key, 'es'), "Missing es translation: {$key}");
            $this->assertTrue(trans()->has($key, 'en'), "Missing en translation: {$key}");
        }
    }
}
