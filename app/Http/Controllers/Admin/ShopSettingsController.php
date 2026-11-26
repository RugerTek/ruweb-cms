<?php

namespace App\Http\Controllers\Admin;

use App\Connectors\ConnectorRegistry;
use App\Contracts\Connectors\Connector;
use App\Http\Controllers\Controller;
use App\Models\Locale;
use App\Models\Product;
use App\Services\Shop\ShopModuleOperations;
use App\Support\ShopModule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings > Shop: the administrator turns the shop module off, to the catalog or to checkout (once CheckoutReadiness
 * reports nothing missing; `choices[].missing` lists what is).
 * Two steps: choosing a mode (GET ?mode=) shows what changes, and only the confirmed PUT switches it. The write
 * is ShopModuleOperations::setMode(), shared with MCP and the installer (docs/shop/catalog.md).
 */
class ShopSettingsController extends Controller
{
    public function edit(Request $request, ShopModule $shop, ShopModuleOperations $operations, ConnectorRegistry $connectors): View
    {
        $current = $shop->mode();
        $pending = $request->query('mode');
        $pending = is_string($pending) && $pending !== $current && $shop->allows($pending) ? $pending : null;

        return view('admin.settings.shop', [
            'current' => $current,
            'stored' => $shop->stored(),
            'limit' => $shop->limit(),
            'choices' => $operations->choices(),
            'pending' => $pending,
            // Same total as "All" in Admin > Products: base-language rows, any status (translations are not products).
            'productCount' => Product::query()->where('locale', Locale::baseCode())->count(),
            // Turning the shop off makes these feeds answer 404 (the platform may pause its scheduled upload).
            // Leaving `checkout` keeps these orders manageable in Admin > Orders (PLAN.md E3.3).
            'openOrders' => $current === 'checkout' && $pending !== null ? $operations->openOrderCount() : 0,
            'pausedConnectors' => $pending === 'off'
                ? array_map(fn (Connector $connector): string => __($connector->label()), array_values($connectors->activeNeedingCatalog()))
                : [],
        ]);
    }

    public function update(Request $request, ShopModule $shop, ShopModuleOperations $operations): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(ShopModule::MODES)],
            'confirm' => ['accepted'],
        ]);

        $leavingCheckout = $shop->mode() === 'checkout' && $data['mode'] !== 'checkout';
        $operations->setMode($request->user(), $data['mode']);

        // Leaving `checkout` says that selling stopped and how many orders still need attention.
        $message = __($data['mode'] === 'catalog' && $leavingCheckout ? 'shop.module_saved_catalog_from_checkout' : 'shop.module_saved_'.$data['mode']);
        $openOrders = $leavingCheckout ? $operations->openOrderCount() : 0;
        if ($openOrders > 0) {
            $message .= ' '.trans_choice('checkout.open_orders_warning', $openOrders, ['count' => $openOrders]);
        }

        return redirect()
            ->route('admin.settings.shop')
            ->with('success', $message);
    }
}
