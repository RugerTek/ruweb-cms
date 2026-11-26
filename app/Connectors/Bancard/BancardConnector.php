<?php

namespace App\Connectors\Bancard;

use App\Connectors\ConnectorAction;
use App\Connectors\ConnectorCategory;
use App\Connectors\ConnectorRequirement;
use App\Contracts\Connectors\Connector;
use App\Models\PaymentMethod;
use App\Shop\Payments\PaymentProviderRegistry;
use App\Support\ShopModule;

/**
 * Bancard card payments as a card in Admin > Connectors (`cms.connectors.bancard`, PLAN.md E3.4). It publishes no
 * feed and has no settings of its own: the keys, the environment and turning it on live in Admin > Payments and
 * shipping (App\Http\Controllers\Admin\ShopPaymentsController), where the card sends the administrator.
 *
 * Requirements, in the order the card explains them (only the first unmet one carries a button):
 * 1. the product catalog on (ConnectorRequirement::shopCatalog());
 * 2. `bancard_payments`: the `bancard` payment method enabled and ready (its provider registered, keys of the active
 *    environment saved and readable on this site) → "Configure payments";
 * 3. `shop_checkout`: selling online on (`shop_mode` = checkout) → Settings > Shop.
 * The connector row mirrors the payment method: ShopPaymentsController activates it when Bancard is turned on with
 * every requirement met and deactivates it when Bancard is turned off, so the card reads `connected` while Bancard
 * takes payments.
 */
class BancardConnector implements Connector
{
    public const KEY = 'bancard';

    /** Requirement: the `bancard` payment method enabled and ready. */
    public const REQUIREMENT_PAYMENTS = 'bancard_payments';

    /** Requirement: the shop selling online (`checkout`). */
    public const REQUIREMENT_CHECKOUT = 'shop_checkout';

    public function __construct(
        private readonly PaymentProviderRegistry $payments,
        private readonly ShopModule $shop,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'checkout.connector_bancard_label';
    }

    public function description(): string
    {
        return 'checkout.connector_bancard_description';
    }

    public function icon(): string
    {
        return 'admin.connectors.icons.bancard';
    }

    public function category(): ConnectorCategory
    {
        return ConnectorCategory::Payments;
    }

    public function requirements(): array
    {
        $catalog = ConnectorRequirement::shopCatalog();
        $ready = $this->bancardReady();
        $configure = new ConnectorAction('admin.shop-payments.edit', 'checkout.connector_bancard_configure', null, 'GET');

        return [
            $catalog,
            new ConnectorRequirement(
                self::REQUIREMENT_PAYMENTS,
                $ready,
                'checkout.connector_bancard_needs_setup',
                $catalog->met && ! $ready ? $configure : null,
            ),
            new ConnectorRequirement(
                self::REQUIREMENT_CHECKOUT,
                $this->shop->checkout(),
                'checkout.connector_bancard_needs_checkout',
                $catalog->met && $ready && ! $this->shop->checkout()
                    ? new ConnectorAction('admin.settings.shop', 'checkout.connector_bancard_activate_selling', null, 'GET')
                    : null,
            ),
        ];
    }

    public function defaults(): array
    {
        return [];
    }

    public function rules(): array
    {
        return [];
    }

    /** The `bancard` payment method is on and its provider reports nothing missing. */
    private function bancardReady(): bool
    {
        $method = PaymentMethod::query()->where('key', self::KEY)->first();

        return $method !== null && $method->enabled && $this->payments->has($method->provider) && $this->payments->isReady($method);
    }
}
