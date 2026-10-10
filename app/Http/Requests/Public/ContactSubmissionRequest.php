<?php

namespace App\Http\Requests\Public;

/**
 * Validation for the starter contact form. Fields, types and rules are
 * code-owned; the Live Editor only edits labels and subject options.
 */
class ContactSubmissionRequest extends PublicFormRequest
{
    public function formKey(): string
    {
        return 'contact';
    }

    protected function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:60'],
            'subject' => ['nullable', 'string', 'max:190'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('public.forms.errors.name_required'),
            'email.required' => __('public.forms.errors.email_required'),
            'email.email' => __('public.forms.errors.email_invalid'),
            'message.required' => __('public.forms.errors.message_required'),
            'message.min' => __('public.forms.errors.message_min'),
        ] + parent::messages();
    }
}
