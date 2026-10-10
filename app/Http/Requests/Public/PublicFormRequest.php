<?php

namespace App\Http\Requests\Public;

use App\Models\Page;
use App\Rules\RecaptchaToken;
use App\Services\Forms\FormToken;
use App\Services\Forms\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for every public form. Adds the shared anti-spam layer:
 * reCAPTCHA v3 token validation, a honeypot field and a minimum fill time.
 *
 * Subclasses declare `formKey()` (the reCAPTCHA action and the key in
 * `config('cms.forms')`) and `fieldRules()` for their own fields. See docs/content/forms.md.
 */
abstract class PublicFormRequest extends FormRequest
{
    /** Honeypot text field that must stay empty. */
    public const HONEYPOT_FIELD = 'website';

    public const MIN_SECONDS = 3;

    abstract public function formKey(): string;

    /** @return array<string, array<int, mixed>> */
    abstract protected function fieldRules(): array;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->fieldRules() + [
            'page_id' => ['required', 'integer', function ($attribute, $value, $fail) {
                if (! $this->formPage()) {
                    $fail(__('submissions.context_invalid'));
                }
            }],
            '_form_token' => ['required', 'string', function ($attribute, $value, $fail) {
                if ($this->tokenAge() === null) {
                    $fail(__('submissions.token_expired'));
                }
            }],
            'recaptcha_token' => [new RecaptchaToken($this->formKey(), $this->ip())],
        ];
    }

    /**
     * Bots fill hidden fields and submit instantly. Callers must treat a
     * tripped honeypot as a silent success: never reveal the check.
     */
    public function isHoneypotTripped(): bool
    {
        if (trim((string) $this->input(self::HONEYPOT_FIELD, '')) !== '') {
            return true;
        }

        return ($this->tokenAge() ?? -1) < self::MIN_SECONDS;
    }

    public function formPage(): ?Page
    {
        $config = config('cms.forms.'.$this->formKey(), []);
        $page = Page::published()->where('locale', app()->getLocale())->find($this->integer('page_id'));
        $section = $config['section'] ?? 'form';
        if (! $page || $page->template_key !== ($config['template'] ?? $this->formKey()) || ! isset($page->sections()[$section]) || ! ($page->sections()[$section]['is_visible'] ?? true)) {
            return null;
        }

        return $page;
    }

    protected function tokenAge(): ?int
    {
        return app(FormToken::class)->age($this->input('_form_token'), $this->integer('page_id'), $this->formKey(), app()->getLocale());
    }

    public function messages(): array
    {
        return ['_form_token.required' => __('submissions.token_expired'), '_form_token.string' => __('submissions.token_expired'), 'page_id.required' => __('submissions.context_invalid')];
    }

    /** Metadata stored next to every submission. */
    public function submissionMeta(): array
    {
        return [
            'locale' => app()->getLocale(),
            'ip' => $this->ip(),
            'user_agent' => mb_substr((string) $this->userAgent(), 0, 500),
            'referer' => mb_substr((string) $this->headers->get('referer'), 0, 500),
            'recaptcha_score' => app(Recaptcha::class)->lastScore(),
        ];
    }
}
