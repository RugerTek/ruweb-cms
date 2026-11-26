<?php

namespace App\Services\Shop;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Providers\ShopServiceProvider;
use App\Services\Editorial\EditorialActor;
use App\Services\Revisions\ContentRevisionLedger;
use App\Support\SettingDefaults;
use App\Support\ShopModule;
use Illuminate\Validation\ValidationException;

/**
 * The single write path of the shop module switch (`shop_mode` setting), shared by the panel (Settings > Shop),
 * `settings_update` / `settings_reset` over MCP and `cms:install --shop`. Switching never creates, converts or
 * deletes shop data: the tables always exist and turning the shop off only hides it (docs/shop/catalog.md).
 */
final class ShopModuleOperations
{
    public function __construct(private readonly ShopModule $shop, private readonly CheckoutReadiness $readiness) {}

    /**
     * Store the administrator's choice and mirror it into this process's registries.
     *
     * @throws ValidationException (`mode`) when the mode is unknown, exceeds `cms.shop.mode` or is not delivered yet,
     *                             or, for `checkout`, while CheckoutReadiness reports something missing (one message
     *                             listing every missing requirement)
     */
    public function setMode(User $actor, string $mode): void
    {
        abort_unless($actor->isAdmin(), 403);
        if (! $this->shop->allows($mode)) {
            throw ValidationException::withMessages(['mode' => $this->refusal($mode)]);
        }
        if ($mode === 'checkout' && ($missing = $this->readiness->missing()) !== []) {
            throw ValidationException::withMessages(['mode' => __('checkout.readiness_blocked', [
                'missing' => implode(', ', array_map(fn (ReadinessItem $item): string => $item->label(), $missing)),
            ])]);
        }

        EditorialActor::run($actor, fn () => app(ContentRevisionLedger::class)->transaction(function () use ($mode) {
            // An install that has not run the data migration yet gets the row from the shared defaults.
            Setting::firstOrCreate(['key' => ShopModule::SETTING], SettingDefaults::for(ShopModule::SETTING));
            Setting::set(ShopModule::SETTING, $mode);
        }, 3));

        Setting::clearCache();
        ShopServiceProvider::syncRegistries();
        // The Bancard card in Admin > Connectors reads "connected" only while selling online with Bancard on.
        app(PaymentMethodOperations::class)->syncConnector($actor);
    }

    /**
     * The modes an administrator can pick now, in order: `available` false means within the limit but not
     * delivered by the code yet (shown as "coming soon"); modes above the limit are left out. `missing` lists what
     * blocks an available `checkout` (CheckoutReadiness, each item with its panel route); empty for other modes.
     *
     * @return list<array{mode: string, available: bool, missing: list<ReadinessItem>}>
     */
    public function choices(): array
    {
        $limit = array_search($this->shop->limit(), ShopModule::MODES, true);
        $choices = [];
        foreach (ShopModule::MODES as $index => $mode) {
            if ($index <= $limit) {
                $available = $this->shop->allows($mode);
                $missing = $mode === 'checkout' && $available ? $this->readiness->missing() : [];
                $choices[] = ['mode' => $mode, 'available' => $available, 'missing' => $missing];
            }
        }

        return $choices;
    }

    /**
     * Orders still to handle (pending, or paid and not delivered). Settings > Shop tells the administrator how many
     * before leaving `checkout`: they stay manageable in Admin > Orders while the catalog is on (PLAN.md E3.3).
     */
    public function openOrderCount(): int
    {
        return Order::query()->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_PAID])->count();
    }

    private function refusal(string $mode): string
    {
        if (! in_array($mode, ShopModule::MODES, true)) {
            return __('shop.module_mode_invalid');
        }
        if ($mode === 'checkout' && ! $this->shop->checkoutAvailable()) {
            return __('shop.module_mode_unavailable');
        }

        return __('shop.module_mode_over_limit', ['limit' => __('shop.module_mode_'.$this->shop->limit())]);
    }
}
