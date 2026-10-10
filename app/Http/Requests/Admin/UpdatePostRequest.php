<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesStaleTranslationFields;
use App\Models\Locale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    use ValidatesStaleTranslationFields;

    public function authorize(): bool
    {
        return in_array($this->user()?->role, config('cms.roles', []));
    }

    public function rules(): array
    {
        return [
            'expires_at' => ['nullable', 'date', 'after:published_at'],
            'title' => ['required', 'string', 'max:255'],
            'locale' => ['nullable', 'string', Rule::in(Locale::installedCodes())],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(Locale::catalogCodes()),
                Rule::unique('posts', 'slug')
                    ->where('locale', $this->input('locale', $this->route('post')->locale))
                    ->ignore($this->route('post')->id),
            ],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['nullable', 'string'],
            'status' => ['required', 'string', 'in:'.implode(',', config('cms.statuses'))],
            'published_at' => ['nullable', 'date'],
            'featured_image' => ['nullable', 'integer', 'exists:media,id'],
            'meta_json' => ['nullable', 'array'],
            'meta_json.title' => ['nullable', 'string', 'max:255'],
            'meta_json.description' => ['nullable', 'string', 'max:320'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', Rule::exists('taxonomies', 'id')->where('type', 'category')],
            'acknowledged_fields' => ['nullable', 'array'],
            'acknowledged_fields.*' => ['string'],
        ];
    }

    protected function staleTranslationEntity(): ?Model
    {
        return $this->route('post');
    }
}
