<?php

namespace App\Shop\Payments;

use App\Contracts\Shop\PaymentProvider;
use App\Models\PaymentMethod;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Read side of the payment providers: cms.shop.payment_providers (key => class implementing
 * App\Contracts\Shop\PaymentProvider, resolved through the container on every call so tests and config changes
 * apply at once) and which payment methods the checkout may offer. A stored method whose provider is not
 * registered (Bancard before its class ships) is never ready and never offered.
 */
final class PaymentProviderRegistry
{
    /** missing() entry of a method whose provider is not registered in this deployment. */
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /**
     * Every registered provider, in config order.
     *
     * @return array<string, PaymentProvider>
     */
    public function all(): array
    {
        $providers = [];
        foreach (array_keys(config('cms.shop.payment_providers', [])) as $key) {
            $providers[$key] = $this->get($key);
        }

        return $providers;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, config('cms.shop.payment_providers', []));
    }

    /**
     * @throws LogicException when the key is not registered, or its class does not implement the contract or reports another key
     */
    public function get(string $key): PaymentProvider
    {
        if (! $this->has($key)) {
            throw new LogicException('Payment provider "'.$key.'" is not registered in cms.shop.payment_providers.');
        }
        $provider = app(config('cms.shop.payment_providers')[$key]);
        if (! $provider instanceof PaymentProvider || $provider->key() !== $key) {
            throw new LogicException('cms.shop.payment_providers.'.$key.' must implement '.PaymentProvider::class.' and return "'.$key.'" from key().');
        }

        return $provider;
    }

    /**
     * The provider of a stored method.
     *
     * @throws LogicException when its provider is not registered
     */
    public function for(PaymentMethod $method): PaymentProvider
    {
        return $this->get($method->provider);
    }

    /** Registered provider and nothing missing (enabled or not: the panel prepares methods before turning them on). */
    public function isReady(PaymentMethod $method): bool
    {
        return $this->missing($method) === [];
    }

    /**
     * What the method lacks (see PaymentProvider::missing()), or [PROVIDER_UNAVAILABLE].
     *
     * @return list<string>
     */
    public function missing(PaymentMethod $method): array
    {
        if (! $this->has($method->provider)) {
            return [self::PROVIDER_UNAVAILABLE];
        }

        return array_values($this->for($method)->missing($method));
    }

    /**
     * Methods the checkout offers: enabled and ready, in panel order.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function available(): Collection
    {
        return PaymentMethod::query()->enabled()->get()
            ->filter(fn (PaymentMethod $method): bool => $this->isReady($method))
            ->values();
    }

    /** One offered method by key; null when it does not exist, is disabled or is not ready. */
    public function availableMethod(string $key): ?PaymentMethod
    {
        $method = PaymentMethod::query()->where('key', $key)->where('enabled', true)->first();

        return $method !== null && $this->isReady($method) ? $method : null;
    }
}
