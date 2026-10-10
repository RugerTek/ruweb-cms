<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesPageSections;
use App\Services\Pages\PageInput;
use Illuminate\Foundation\Http\FormRequest;

class StorePageRequest extends FormRequest
{
    use ValidatesPageSections;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return (new PageInput($this->all()))->rules();
    }
}
