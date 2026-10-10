<?php

namespace App\Services\Pages;

use App\Http\Requests\Admin\Concerns\ValidatesPageSections;
use App\Http\Requests\Admin\Concerns\ValidatesStaleTranslationFields;
use App\Models\Locale;
use App\Models\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class PageInput
{
    use ValidatesPageSections;
    use ValidatesStaleTranslationFields;

    public function __construct(private array $data, private ?Page $page = null, private bool $partial = false, private bool $preserveContent = false) {}

    public function input(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $key === 'template_key' ? ($this->page?->template_key ?? $default) : $default);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function merge(array $data): void
    {
        $this->data = array_replace($this->data, $data);
    }

    public function validate(): array
    {
        if (! $this->preserveContent) {
            $this->prepareForValidation();
        }
        $validator = Validator::make($this->data, $this->rules(), $this->messages());
        if (! $this->partial) {
            $this->withValidator($validator);
        }

        $validated = $validator->validate();
        // Bearer patches preserve unknown sibling keys and explicit null removals.
        if ($this->preserveContent && array_key_exists('content_json', $this->data)) {
            $validated['content_json'] = $this->data['content_json'];
        }

        return $validated;
    }

    public function rules(): array
    {
        $rules = [
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
            'title' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(Locale::installedCodes())],
            'slug' => [
                'required',
                'string',
                'max:255',
                Rule::notIn(Locale::catalogCodes()),
                Rule::unique('pages', 'slug')
                    ->where('locale', $this->partial ? $this->page->locale : ($this->input('locale') ?? $this->page?->locale ?? Locale::baseCode()))
                    ->ignore($this->page?->id),
            ],
            'template_key' => ['required', 'string', 'in:'.implode(',', array_keys(config('cms.templates')))],
            'status' => ['required', 'string', 'in:'.implode(',', config('cms.statuses'))],
            'featured_image' => ['nullable', 'integer', 'exists:media,id'],
            'content_json' => ['nullable', 'array'],
            'content_json.meta' => ['nullable', 'array'],
            'content_json.meta.title' => ['nullable', 'string', 'max:255'],
            'content_json.meta.description' => ['nullable', 'string', 'max:320'],
            'content_json.sections' => ['nullable', 'array'],
            'editor_field_origins' => ['nullable', 'array'],
            'editor_field_origins.*' => ['string', 'max:2048'],
            'acknowledged_fields' => ['nullable', 'array'],
            'acknowledged_fields.*' => ['string'],
        ] + $this->sectionRules();
        if ($this->partial) {
            foreach (['title', 'slug', 'template_key', 'status'] as $field) {
                $rules[$field][0] = 'sometimes';
            }
            $rules['content_json'] = ['sometimes', 'array'];
            unset($rules['locale'], $rules['published_at'], $rules['expires_at'], $rules['status']);
        }

        return $rules;
    }

    protected function staleTranslationEntity(): ?Model
    {
        return $this->page;
    }
}
