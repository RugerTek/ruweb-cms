<?php

namespace App\Http\Requests\Admin;

use App\Support\Username;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('can_manage_custom_code')) {
            $this->merge(['can_manage_custom_code' => $this->input('role') === 'admin' && $this->boolean('can_manage_custom_code')]);
        }
        if ($this->has('username')) {
            $this->merge(['username' => Username::normalize($this->input('username'))]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => Username::rules(),
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'can_manage_custom_code' => ['sometimes', 'boolean'],
            'role' => ['required', 'string', 'in:'.implode(',', config('cms.roles', []))],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
