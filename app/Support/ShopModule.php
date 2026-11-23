<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Single read path for the optional shop module. The administrator switches it from the panel (the `shop_mode`
 * setting, written only through App\Services\Shop\ShopModuleOperations); `cms.shop.mode` in config/cms.php is the
 * versioned limit a deployment allows (`off`: the site cannot turn the shop on, `catalog`: up to the catalog,
 * `checkout`: no limit). The effective mode is the lower of both. Deployment capability only: it decides which
 * routes answer and which registries and tools exist, never whether an actor may use them.
 */
final class ShopModule
{
    public const MODES = ['off', 'catalog', 'checkout'];

    /** Key of the setting the administrator changes (`settings` table, group `shop`). */
    public const SETTING = 'shop_mode';

    /** Effective mode: the stored choice, lowered to the config limit. */
    public function mode(): string
    {
        $limit = $this->limit();
        $stored = $this->stored();

        return array_search($stored, self::MODES, true) <= array_search($limit, self::MODES, true) ? $stored : $limit;
    }

    /** The highest mode this deployment allows (`cms.shop.mode`). */
    public function limit(): string
    {
        $limit = config('cms.shop.mode', 'checkout');
        if (! in_array($limit, self::MODES, true)) {
            throw new \LogicException('Invalid cms.shop.mode: '.json_encode($limit).'. Expected off, catalog or checkout in config/cms.php.');
        }

        return $limit;
    }

    /**
     * The administrator's choice. `off` while the settings table or its row does not exist yet (fresh install,
     * `migrate` in progress, artisan commands without a database). Setting::get() caches it for the process.
     */
    public function stored(): string
    {
        try {
            $stored = Setting::get(self::SETTING, 'off');
        } catch (\Exception) {
            return 'off';
        }
        if (! in_array($stored, self::MODES, true)) {
            throw new \LogicException('Invalid '.self::SETTING.' setting: '.json_encode($stored).'. Expected off, catalog or checkout.');
        }

        return $stored;
    }

    /** Whether the administrator may choose this mode: a known mode, within the config limit and delivered by the code. */
    public function allows(string $mode): bool
    {
        if (! in_array($mode, self::MODES, true) || ($mode === 'checkout' && ! $this->checkoutAvailable())) {
            return false;
        }

        return array_search($mode, self::MODES, true) <= array_search($this->limit(), self::MODES, true);
    }

    /** Cart and checkout ship with routes/shop-checkout.php (PLAN.md §3); until then `checkout` behaves as `catalog`. */
    public function checkoutAvailable(): bool
    {
        return is_file(base_path('routes/shop-checkout.php'));
    }

    /**
     * Editorial types the module registers while the catalog is on, whatever the current mode. Route constraints
     * are compiled once (route:cache), so they include these and App\Http\Middleware\RequireRegisteredType checks
     * the live registry on every request.
     *
     * @return list<string>
     */
    public function editorialTypes(): array
    {
        return (config('cms.shop.collection.editorial_history') ?? false) === true ? ['products'] : [];
    }

    /**
     * Taxonomy types the module registers while the catalog is on, whatever the current mode (see editorialTypes()).
     *
     * @return list<string>
     */
    public function taxonomyTypes(): array
    {
        return array_values(config('cms.shop.taxonomy_types', []));
    }

    /** Products, public catalog, panel and MCP. True for `checkout` too: there is no checkout without a catalog. */
    public function catalog(): bool
    {
        return $this->mode() !== 'off';
    }

    /** Cart, checkout, orders and payments on top of the catalog. */
    public function checkout(): bool
    {
        return $this->mode() === 'checkout';
    }

    /** Public catalog prefix without slashes (`productos`). */
    public function path(): string
    {
        return trim((string) config('cms.shop.path', 'productos'), '/');
    }
}
