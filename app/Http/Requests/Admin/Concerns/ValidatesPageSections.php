<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Models\Page;
use App\Support\LinkDestination;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

trait ValidatesPageSections
{
    protected function prepareForValidation(): void
    {
        $content = $this->input('content_json', []);
        if (! is_array($content)) {
            return;
        }
        foreach (['sections.features.logos', 'sections.form.subject_options'] as $path) {
            if (Arr::has($content, $path) && in_array(data_get($content, $path), ['', null], true)) {
                data_set($content, $path, []);
            }
        }
        $this->merge(['content_json' => $content]);
    }

    public function messages(): array
    {
        return [
            'required' => __('admin.editor_validation_required'),
            'string' => __('admin.editor_validation_string'),
            'max.string' => __('admin.editor_validation_max_string'),
            'regex' => __('admin.editor_validation_regex'),
            'in' => __('admin.editor_validation_in'),
            'exists' => __('admin.editor_validation_exists'),
            'boolean' => __('admin.editor_validation_boolean'),
            'unique' => __('admin.editor_validation_unique'),
            'array' => __('admin.editor_validation_array'),
        ];
    }

    private function sectionRules(): array
    {
        $rules = [];
        foreach (config('cms.templates.'.$this->input('template_key', 'default').'.schema', []) as $path => $field) {
            if (! str_starts_with($path, 'sections.')) {
                continue;
            }
            if (str_contains($path, '.*.')) {
                $parent = strstr($path, '.*.', true);
                $rules['content_json.'.$parent] = ['nullable', 'array'];
            }
            $type = $field['type'] ?? 'text';
            $rules['content_json.'.$path] = match ($type) {
                'boolean' => ['nullable', 'boolean'],
                'media' => ['nullable', 'integer', 'exists:media,id'],
                'page_reference' => ['nullable', Rule::in([LinkDestination::CUSTOM, ...Page::whereNotNull('translation_group_id')->pluck('translation_group_id')->all()])],
                'url' => ['nullable', 'string', 'max:2048', 'regex:'.LinkDestination::CUSTOM_URL_PATTERN],
                default => ['nullable', 'string', 'max:'.($type === 'textarea' ? 100000 : 255)],
            };
        }
        if ($this->input('content_json.sections.cta.button_page') && $this->input('content_json.sections.cta.button_page') !== LinkDestination::CUSTOM) {
            $rules['content_json.sections.cta.button_url'] = ['exclude'];
        }

        return $rules;
    }
}
