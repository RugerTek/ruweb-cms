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
 * Settings > Shop: the administrator turns the shop module off, to the catalog or (when delivered) to checkout.
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
            'pausedConnectors' => $pending === 'off'
                ? array_map(fn (Connector $connector): string => __($connector->label()), array_values($connectors->activeNeedingCatalog()))
                : [],
        ]);
    }

    public function update(Request $request, ShopModuleOperations $operations): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'string', Rule::in(ShopModule::MODES)],
            'confirm' => ['accepted'],
        ]);

        $operations->setMode($request->user(), $data['mode']);

        return redirect()
            ->route('admin.settings.shop')
            ->with('success', __('shop.module_saved_'.$data['mode']));
    }
}
