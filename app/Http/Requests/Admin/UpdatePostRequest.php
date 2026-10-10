<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesStaleTranslationFields;
use App\Services\Posts\PostInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePostRequest extends FormRequest
{
    use ValidatesStaleTranslationFields;

    public function authorize(): bool
    {
        return in_array($this->user()?->role, config('cms.roles', []));
    }

    public function rules(): array
    {
        return (new PostInput($this->all(), $this->route('post')))->rules();
    }

    protected function staleTranslationEntity(): ?Model
    {
        return $this->route('post');
    }
}
