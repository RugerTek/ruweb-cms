<?php

namespace App\Http\Requests\Public;

use App\Models\Order;
use App\Rules\RecaptchaToken;
use App\Services\Forms\FormToken;
use App\Services\Shop\CheckoutService;

/**
 * The public checkout form (PLAN.md E3.2 step 2) with the full anti-spam layer of AGENTS.md rule 5: CSRF (web
 * group), honeypot, `throttle:forms` (route), reCAPTCHA v3 (action `checkout`) and a minimum fill time through a
 * FormToken issued for page 0 / form `checkout` (the checkout is not a CMS page).
 *
 * Unlike other public forms, a token younger than MIN_SECONDS is a visible error (`checkout.error_too_fast`) and
 * not a silent drop: a real customer whose fill-time script did not run must not lose the order. Only the honeypot
 * field answers as a fake success (isHoneypotTripped()). Availability of the delivery and payment choices is
 * checked by CheckoutService::place(), with the cart locked.
 */
class CheckoutRequest extends PublicFormRequest
{
    public const FORM = 'checkout';

    /** FormToken page id of the checkout form (it is not a CMS page). */
    public const PAGE_ID = 0;

    public function formKey(): string
    {
        return self::FORM;
    }

    protected function fieldRules(): array
    {
        $delivery = 'required_if:delivery_method,'.Order::DELIVERY_DELIVERY;
        $rules = [
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email:rfc', 'max:190'],
            'customer_phone' => ['required', 'string', 'max:60'],
            'delivery_method' => ['required', 'string', 'in:'.implode(',', Order::DELIVERY_METHODS)],
            'shipping_zone_id' => ['nullable', $delivery, 'integer'],
            'address' => ['nullable', $delivery, 'string', 'max:255'],
            'city' => ['nullable', $delivery, 'string', 'max:120'],
            'department' => ['nullable', 'string', 'max:120'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'payment_method' => ['required', 'string', 'max:60'],
        ];
        if (app(CheckoutService::class)->termsPage() !== null) {
            $rules['accept_terms'] = ['accepted'];
        }

        return $rules;
    }

    public function rules(): array
    {
        return $this->fieldRules() + [
            '_form_token' => ['required', 'string', function ($attribute, $value, $fail) {
                $age = $this->tokenAge();
                if ($age === null) {
                    $fail(__('submissions.token_expired'));
                } elseif ($age < self::MIN_SECONDS) {
                    $fail(__('checkout.error_too_fast'));
                }
            }],
            'recaptcha_token' => [new RecaptchaToken($this->formKey(), $this->ip())],
        ];
    }

    /** Only the hidden field: the fill time is a visible validation error here (see the class comment). */
    public function isHoneypotTripped(): bool
    {
        return trim((string) $this->input(self::HONEYPOT_FIELD, '')) !== '';
    }

    protected function tokenAge(): ?int
    {
        return app(FormToken::class)->age($this->input('_form_token'), self::PAGE_ID, self::FORM, app()->getLocale());
    }

    public function messages(): array
    {
        $address = __('checkout.error_address_required');

        return [
            'customer_name.required' => __('public.forms.errors.name_required'),
            'customer_email.required' => __('public.forms.errors.email_required'),
            'customer_email.email' => __('public.forms.errors.email_invalid'),
            'shipping_zone_id.required_if' => __('checkout.error_zone_required'),
            'address.required_if' => $address,
            'city.required_if' => $address,
            'payment_method.required' => __('checkout.error_method_unavailable'),
            'accept_terms.accepted' => __('checkout.error_terms_required'),
        ] + parent::messages();
    }

    /**
     * Fields CheckoutService::place() takes.
     *
     * @return array<string, mixed>
     */
    public function orderData(): array
    {
        return $this->safe()->only(['customer_name', 'customer_email', 'customer_phone', 'delivery_method', 'shipping_zone_id', 'address', 'city', 'department', 'reference', 'notes', 'payment_method']);
    }

    /**
     * Request metadata stored on the order.
     *
     * @return array{ip: string|null, user_agent: string, recaptcha_score: float|null, locale: string}
     */
    public function orderMeta(): array
    {
        $meta = $this->submissionMeta();

        return ['ip' => $meta['ip'], 'user_agent' => $meta['user_agent'], 'recaptcha_score' => $meta['recaptcha_score'], 'locale' => $meta['locale']];
    }
}
