<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesPageSections;
use App\Http\Requests\Admin\Concerns\ValidatesStaleTranslationFields;
use App\Services\Pages\PageInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePageRequest extends FormRequest
{
    use ValidatesPageSections;
    use ValidatesStaleTranslationFields;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return (new PageInput($this->all(), $this->route('page')))->rules();
    }

    protected function staleTranslationEntity(): ?Model
    {
        return $this->route('page');
    }
}
