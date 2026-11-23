<?php

namespace App\Connectors;

/**
 * A panel button that resolves something for a connector (activate the product catalog). The route receives the
 * connector key as `{connector}`; texts are translation keys.
 */
final class ConnectorAction
{
    /**
     * @param  string  $route  panel route name (`admin.connectors.enable-catalog`)
     * @param  string  $label  translation key of the button
     * @param  string|null  $confirm  translation key of the confirmation text; null = no confirmation
     * @param  string  $method  HTTP method of the form
     */
    public function __construct(
        public readonly string $route,
        public readonly string $label,
        public readonly ?string $confirm = null,
        public readonly string $method = 'POST',
    ) {}

    /** Form action for this connector's card. */
    public function url(string $connector): string
    {
        return route($this->route, ['connector' => $connector]);
    }
}
