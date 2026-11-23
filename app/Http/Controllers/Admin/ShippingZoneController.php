<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\MoneyHelper;
use App\Http\Controllers\Controller;
use App\Models\ShippingZone;
use App\Services\Shop\CheckoutReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Delivery zones of Admin > Payments and shipping (PLAN.md P2 and E3.4): name, fixed price in the shop currency,
 * enabled and order. Administrators only (routes/shop-checkout-admin.php). Orders copy the zone name and price when
 * placed, so editing or deleting a zone never changes an existing order. While selling online, the last delivery
 * option cannot be removed or turned off (CheckoutReadiness::guard()).
 */
class ShippingZoneController extends Controller
{
    public const NAME_MAX = 120;

    /** Highest fixed price accepted, in minor units of the shop currency (guaraníes have none). */
    public const PRICE_MAX = 100_000_000;

    public function __construct(private readonly CheckoutReadiness $readiness) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->readiness->guard(fn () => ShippingZone::query()->create($data));

        return redirect()->route('admin.shop-payments.edit')->withFragment('shipping')->with('success', __('checkout.zone_saved'));
    }

    public function update(Request $request, ShippingZone $zone): RedirectResponse
    {
        $data = $this->validated($request);
        $this->readiness->guard(fn () => $zone->fill($data)->save(), 'zone');

        return redirect()->route('admin.shop-payments.edit')->withFragment('shipping')->with('success', __('checkout.zone_saved'));
    }

    public function destroy(ShippingZone $zone): RedirectResponse
    {
        $this->readiness->guard(fn () => $zone->delete(), 'zone');

        return redirect()->route('admin.shop-payments.edit')->withFragment('shipping')->with('success', __('checkout.zone_deleted'));
    }

    /**
     * @return array{name: string, price_amount: int, enabled: bool, sort_order: int}
     *
     * @throws ValidationException
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.self::NAME_MAX],
            // Typed as the customer reads it: "40.000" or "40000".
            'price' => ['required', 'string', 'max:20', 'regex:/^\d[\d.\s]*$/'],
            'enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
        $price = MoneyHelper::parseInput($data['price']);
        if ($price > self::PRICE_MAX) {
            throw ValidationException::withMessages(['price' => __('validation.max.numeric', ['attribute' => __('checkout.zone_price'), 'max' => MoneyHelper::format(self::PRICE_MAX)])]);
        }

        return [
            'name' => trim($data['name']),
            'price_amount' => $price,
            'enabled' => $request->boolean('enabled'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
