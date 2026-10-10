<?php

namespace App\Services\Posts;

use App\Http\Requests\Admin\Concerns\ValidatesStaleTranslationFields;
use App\Models\Locale;
use App\Models\Post;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class PostInput
{
    use ValidatesStaleTranslationFields;

    public function __construct(private array $data, private ?Post $post = null, private bool $partial = false, private bool $draftOnly = false) {}

    public function input(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function all(): array
    {
        return $this->data;
    }

    public function validate(): array
    {
        $validator = Validator::make($this->data, $this->rules());
        if (! $this->partial) {
            $this->withValidator($validator);
        }
        $data = $validator->validate();
        if ($this->draftOnly && array_key_exists('meta_json', $this->data)) {
            $data['meta_json'] = $this->data['meta_json'];
        }

        return $data;
    }

    public function rules(): array
    {
        $locale = $this->partial ? $this->post->locale : ($this->input('locale') ?? $this->post?->locale ?? Locale::baseCode());
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(Locale::catalogCodes()), Rule::unique('posts', 'slug')->where('locale', $locale)->ignore($this->post?->id)],
            'locale' => ['nullable', 'string', Rule::in(Locale::installedCodes())],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'status' => ['required', 'string', Rule::in(config('cms.statuses'))],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
            'featured_image' => ['nullable', 'integer', 'exists:media,id'],
            'meta_json' => ['nullable', 'array'],
            'meta_json.title' => ['nullable', 'string', 'max:255'],
            'meta_json.description' => ['nullable', 'string', 'max:320'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', Rule::exists('taxonomies', 'id')->where('type', 'category')->where('locale', $locale)],
            'acknowledged_fields' => ['nullable', 'array'],
            'acknowledged_fields.*' => ['string'],
            'expected_revision' => ['nullable', 'string', 'size:64'],
        ];
        if ($this->draftOnly) {
            unset($rules['published_at'], $rules['expires_at']);
        }
        if ($this->partial) {
            $rules['title'][0] = 'sometimes';
            $rules['slug'][0] = 'sometimes';
            unset($rules['locale'], $rules['status']);
        }

        return $rules;
    }

    protected function staleTranslationEntity(): ?Model
    {
        return $this->post;
    }
}
