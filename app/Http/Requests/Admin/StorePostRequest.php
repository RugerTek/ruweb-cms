<?php

namespace App\Http\Requests\Admin;

use App\Services\Posts\PostInput;
use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, config('cms.roles', []));
    }

    public function rules(): array
    {
        return (new PostInput($this->all(), null))->rules();
    }
}
