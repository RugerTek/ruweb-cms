<?php

namespace App\Http\Requests\Public;

use App\Rules\RecaptchaToken;
use App\Services\Forms\Recaptcha;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request for every public form. Adds the shared anti-spam layer:
 * reCAPTCHA v3 token validation, a honeypot field and a minimum fill time.
 *
 * Subclasses declare `formKey()` (the reCAPTCHA action and the key in
 * `config('cms.forms')`) and `fieldRules()` for their own fields. See docs/forms.md.
 */
abstract class PublicFormRequest extends FormRequest
{
    /** Honeypot text field that must stay empty. */
    public const HONEYPOT_FIELD = 'website';

    /** Hidden render timestamp; submissions faster than this are bots. */
    public const TIMESTAMP_FIELD = '_ts';

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
            'page_id' => ['nullable', 'integer', 'exists:pages,id'],
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

        $rendered = $this->input(self::TIMESTAMP_FIELD);

        return is_numeric($rendered) && (time() - (int) $rendered) < self::MIN_SECONDS;
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
