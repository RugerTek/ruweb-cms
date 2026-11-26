<?php

namespace App\Http\Requests\Admin;

use App\Services\Menus\MenuOperations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncMenuItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, config('cms.roles', []));
    }

    public function rules(): array
    {
        return [
            'items' => ['present', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.parent_id' => ['nullable', 'integer'],
            'items.*.label' => ['required', 'string', 'max:255'],
            // MenuOperations decides product items: offered only while the shop catalog is on; switched off, the
            // stored ones are kept and new ones are refused there.
            'items.*.type' => ['required', 'string', Rule::in(MenuOperations::syncableTypes())],
            'items.*.linkable_id' => ['nullable', 'integer'],
            'items.*.url' => ['nullable', 'string', 'max:2048'],
            'items.*.target' => ['required', 'string', 'in:_self,_blank'],
            'items.*.order' => ['required', 'integer', 'min:0'],
        ];
    }
}
