<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private ?string $resolvedThrottleKey = null;

    protected function prepareForValidation(): void
    {
        // Keep existing email-only submissions compatible with the unified field.
        $login = $this->input('login', $this->input('email'));
        $this->merge(['login' => is_string($login) ? Str::lower(trim($login)) : $login]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt([
            $this->identityColumn() => $this->input('login'),
            'password' => $this->input('password'),
        ], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));
        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        if ($this->resolvedThrottleKey === null) {
            $id = User::query()->where($this->identityColumn(), $this->input('login'))->value('id');
            $identity = $id === null ? 'login:'.$this->input('login') : 'user:'.$id;
            $this->resolvedThrottleKey = 'admin-login:'.Str::transliterate($identity).'|'.$this->ip();
        }

        return $this->resolvedThrottleKey;
    }

    private function identityColumn(): string
    {
        return str_contains($this->input('login'), '@') ? 'email' : 'username';
    }
}
