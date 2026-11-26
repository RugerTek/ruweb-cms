<?php

namespace App\Services\Shop;

/**
 * One requirement for selling online (PLAN.md E3.3), as CheckoutReadiness reports it: a stable key, whether the
 * site meets it now, and the panel route that resolves it. The label lives in lang/{es,en}/checkout.php under
 * `readiness_<key>`.
 */
final class ReadinessItem
{
    public function __construct(
        public readonly string $key,
        public readonly bool $met,
        public readonly string $route,
    ) {}

    /** Text for the panel and the MCP error, in the current locale. */
    public function label(): string
    {
        return __('checkout.readiness_'.$this->key);
    }

    /** Panel URL that resolves this requirement. */
    public function url(): string
    {
        return route($this->route);
    }

    /** @return array{key: string, met: bool, label: string, route: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'met' => $this->met, 'label' => $this->label(), 'route' => $this->route];
    }
}
