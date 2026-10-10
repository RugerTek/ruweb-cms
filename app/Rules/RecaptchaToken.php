<?php

namespace App\Rules;

use App\Services\Forms\Recaptcha;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class RecaptchaToken implements ValidationRule
{
    /** Run even when the token is missing: an absent token must fail when reCAPTCHA is on. */
    public bool $implicit = true;

    public function __construct(
        protected string $action,
        protected ?string $ip = null,
        protected ?string $hostname = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(Recaptcha::class)->verify(is_string($value) ? $value : null, $this->ip, $this->action, $this->hostname)) {
            $fail(__('public.forms.recaptcha_failed'));
        }
    }
}
