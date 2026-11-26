<?php

namespace App\Services\Shop;

use App\Models\Setting;
use App\Models\ShippingZone;
use App\Shop\Payments\PaymentProviderRegistry;
use App\Support\ShopModule;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What a site needs before the administrator can switch the shop to `checkout` (PLAN.md E3.3). Read by
 * ShopModuleOperations::setMode() (panel, MCP `settings_update`, installer) and by Settings > Shop to list what
 * is missing, each item with the panel screen that resolves it:
 *
 * 1. `payment_method`: at least one payment method enabled and ready (PaymentProviderRegistry::available(): bank
 *    transfer with its bank details, cash on delivery with its text, Bancard with readable keys for its environment).
 * 2. `delivery`: store pickup on with its address, or at least one enabled delivery zone.
 * 3. `orders_email`: a valid address for order notices (`shop_orders_email`, or `contact_email` when it is empty).
 * 4. `mail`: sending email turned on (`mail_enabled`, Admin > Email): without it nobody hears about an order, neither
 *    the customer (their order link) nor the store (decision Hermes, stage 3 wave 2).
 */
final class CheckoutReadiness
{
    public const PAYMENT_METHOD = 'payment_method';

    public const DELIVERY = 'delivery';

    public const ORDERS_EMAIL = 'orders_email';

    public const MAIL = 'mail';

    /** Panel screen with payment methods, shipping and the orders email (App\Http\Controllers\Admin\ShopPaymentsController). */
    public const ROUTE = 'admin.shop-payments.edit';

    /** Panel screen where sending email is configured and turned on (App\Http\Controllers\Admin\EmailController). */
    public const MAIL_ROUTE = 'admin.email.index';

    public function __construct(private readonly PaymentProviderRegistry $payments) {}

    /**
     * Every requirement, met or not, in display order.
     *
     * @return list<ReadinessItem>
     */
    public function items(): array
    {
        return [
            new ReadinessItem(self::PAYMENT_METHOD, $this->payments->available()->isNotEmpty(), self::ROUTE),
            new ReadinessItem(self::DELIVERY, $this->hasDelivery(), self::ROUTE),
            new ReadinessItem(self::ORDERS_EMAIL, $this->ordersEmail() !== null, self::ROUTE),
            new ReadinessItem(self::MAIL, (bool) Setting::get('mail_enabled', false), self::MAIL_ROUTE),
        ];
    }

    /**
     * The requirements the site does not meet yet.
     *
     * @return list<ReadinessItem>
     */
    public function missing(): array
    {
        return array_values(array_filter($this->items(), fn (ReadinessItem $item): bool => ! $item->met));
    }

    public function isReady(): bool
    {
        return $this->missing() === [];
    }

    /**
     * Run a write of Admin > Payments and shipping in a transaction and keep a selling site selling: while the shop is
     * in `checkout`, a write that leaves unmet a requirement that was met before (the last ready payment method
     * turned off, the last delivery option removed…) is rolled back with a ValidationException under $errorKey.
     * Requirements already unmet before the write never block it (fixing one thing must not wait for another).
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     *
     * @throws ValidationException
     */
    public function guard(Closure $write, string $errorKey = 'selling'): mixed
    {
        Setting::clearCache();
        $selling = app(ShopModule::class)->checkout();
        $before = $selling ? array_map(fn (ReadinessItem $item): string => $item->key, $this->missing()) : [];

        try {
            return DB::transaction(function () use ($write, $selling, $before, $errorKey) {
                $result = $write();
                Setting::clearCache();
                if ($selling) {
                    $broken = array_values(array_filter($this->missing(), fn (ReadinessItem $item): bool => ! in_array($item->key, $before, true)));
                    if ($broken !== []) {
                        throw ValidationException::withMessages([$errorKey => __('checkout.selling_would_stop', [
                            'missing' => implode(', ', array_map(fn (ReadinessItem $item): string => $item->label(), $broken)),
                        ])]);
                    }
                }

                return $result;
            });
        } finally {
            Setting::clearCache();
        }
    }

    /** Address that receives order notices: `shop_orders_email`, else `contact_email`; null when neither is valid. */
    public function ordersEmail(): ?string
    {
        foreach (['shop_orders_email', 'contact_email'] as $key) {
            $email = Setting::get($key);
            if (is_string($email) && trim($email) !== '') {
                return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false ? trim($email) : null;
            }
        }

        return null;
    }

    private function hasDelivery(): bool
    {
        $address = Setting::get('shop_pickup_address');
        if ((bool) Setting::get('shop_pickup_enabled', false) && is_string($address) && trim($address) !== '') {
            return true;
        }

        return ShippingZone::query()->enabled()->exists();
    }
}
