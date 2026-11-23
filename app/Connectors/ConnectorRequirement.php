<?php

namespace App\Connectors;

use App\Support\ShopModule;

/** Something a connector needs from the site, evaluated when asked (App\Contracts\Connectors\Connector::requirements()). */
final class ConnectorRequirement
{
    public const SHOP_CATALOG = 'shop_catalog';

    /**
     * @param  string  $key  stable identifier (`shop_catalog`)
     * @param  string  $message  translation key explaining what is missing (shown while unmet)
     * @param  ConnectorAction|null  $action  button that resolves it, when the administrator can
     */
    public function __construct(
        public readonly string $key,
        public readonly bool $met,
        public readonly string $message,
        public readonly ?ConnectorAction $action = null,
    ) {}

    /**
     * The product catalog must be on. While it is off the card offers to turn it on, unless `cms.shop.mode` does
     * not allow the catalog on this site (then it only explains). The action goes to
     * ConnectorOperations::enableCatalog(); its confirmation text takes `:path` (ShopModule::path()).
     */
    public static function shopCatalog(): self
    {
        $shop = app(ShopModule::class);
        if ($shop->catalog()) {
            return new self(self::SHOP_CATALOG, true, 'connectors.requirement_shop_catalog');
        }
        if (! $shop->allows('catalog')) {
            return new self(self::SHOP_CATALOG, false, 'connectors.requirement_shop_unavailable');
        }

        return new self(self::SHOP_CATALOG, false, 'connectors.requirement_shop_catalog', new ConnectorAction(
            'admin.connectors.enable-catalog',
            'connectors.action_enable_catalog',
            'connectors.confirm_enable_catalog',
        ));
    }
}
