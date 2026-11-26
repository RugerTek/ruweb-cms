<?php

namespace App\Services\Shop;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Locale;
use App\Models\Product;
use App\Models\ProductVariant;
use App\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Guest cart (PLAN.md E3.2 step 1): the `ruweb_cart` cookie points at a `carts` row; lines keep only product,
 * variant and quantity. Prices, stock and publication are read again every time the cart is summarized, from the
 * base-language row of the product (where commerce fields live), so a line can turn unavailable and blocks the
 * checkout until the customer fixes it.
 *
 * Line problems (`problem` in summary()): `unpublished` (product, base row or variant gone or not public),
 * `no_price`, `variant_required`, `out_of_stock`, `insufficient_stock`.
 */
class CartService
{
    public const COOKIE = 'ruweb_cart';

    /** Most units of one line (`checkout.cart_quantity_invalid`). */
    public const MAX_QUANTITY = 99;

    public const PROBLEM_UNPUBLISHED = 'unpublished';

    public const PROBLEM_NO_PRICE = 'no_price';

    public const PROBLEM_VARIANT_REQUIRED = 'variant_required';

    public const PROBLEM_OUT_OF_STOCK = 'out_of_stock';

    public const PROBLEM_INSUFFICIENT_STOCK = 'insufficient_stock';

    /** The live cart behind the request cookie; null without cookie, or when it is unknown or expired. */
    public function current(Request $request): ?Cart
    {
        $token = $request->cookie(self::COOKIE);

        return is_string($token) ? Cart::findByToken($token) : null;
    }

    /** The current cart, or a new empty one (the caller attaches cookie() to its response). */
    public function currentOrCreate(Request $request): Cart
    {
        return $this->current($request) ?? Cart::create(['token' => Cart::generateToken(), 'expires_at' => $this->expiry()]);
    }

    /** The `ruweb_cart` cookie for a cart: 64 characters, cart_days long, HttpOnly, SameSite=Lax. */
    public function cookie(Cart $cart): Cookie
    {
        return cookie(self::COOKIE, $cart->token, $this->days() * 24 * 60, null, null, null, true, false, 'lax');
    }

    public function forgetCookie(): Cookie
    {
        return cookie()->forget(self::COOKIE);
    }

    /**
     * Add units of a product (any language row of it; the line points at the base row) or of one of its variants.
     *
     * @throws ValidationException (`product_id`, `variant_id` or `quantity`) when it cannot be bought
     */
    public function add(Cart $cart, Product $product, ?int $variantId, int $quantity = 1): CartItem
    {
        $commerce = $this->commerceRow($product);
        if ($commerce === null) {
            throw ValidationException::withMessages(['product_id' => __('checkout.cart_item_unpublished')]);
        }
        $variant = null;
        if ($commerce->has_variants) {
            $variant = $variantId === null ? null : $commerce->variants()->whereKey($variantId)->first();
            if ($variant === null) {
                throw ValidationException::withMessages(['variant_id' => __('checkout.cart_item_variant_required')]);
            }
        } elseif ($variantId !== null) {
            throw ValidationException::withMessages(['variant_id' => __('checkout.cart_item_unpublished')]);
        }

        $item = $cart->items()->where('line_key', CartItem::lineKey($commerce->id, $variant?->id))->first()
            ?? new CartItem(['cart_id' => $cart->id, 'product_id' => $commerce->id, 'variant_id' => $variant?->id, 'quantity' => 0]);
        $total = $item->quantity + $this->assertQuantity($quantity);
        $this->assertQuantity($total);
        $this->assertPurchasable($commerce, $variant, $total);

        $item->quantity = $total;
        $item->save();
        $this->touch($cart);

        return $item;
    }

    /**
     * Set the quantity of a line of this cart.
     *
     * @throws ValidationException (`quantity`) when the quantity is out of range or above the stock
     */
    public function update(Cart $cart, CartItem $item, int $quantity): CartItem
    {
        $this->assertOwned($cart, $item);
        $this->assertQuantity($quantity);
        $commerce = $item->product;
        if ($commerce !== null && $this->isLive($commerce)) {
            $this->assertStock($commerce, $item->variant, $quantity);
        }

        $item->update(['quantity' => $quantity]);
        $this->touch($cart);

        return $item;
    }

    public function remove(Cart $cart, CartItem $item): void
    {
        $this->assertOwned($cart, $item);
        $item->delete();
        $this->touch($cart);
    }

    /** Lines in the cart (units), without reading products. */
    public function count(?Cart $cart): int
    {
        return $cart === null ? 0 : (int) $cart->items()->sum('quantity');
    }

    /**
     * The cart as it stands now: each line with its current price and problem, the subtotal of the buyable lines,
     * and whether anything blocks the checkout.
     *
     * @return array{lines: list<array{item: CartItem, product: Product|null, variant: ProductVariant|null, title: string, variant_name: string|null, sku: string|null, url: string|null, quantity: int, unit_price: Money|null, line_total: Money|null, available: int|null, problem: string|null}>, subtotal: Money, count: int, blocked: bool, empty: bool}
     */
    public function summary(?Cart $cart): array
    {
        $items = $cart === null ? collect() : $cart->items()->with(['product.variants', 'product.translations', 'variant'])->get();
        $lines = [];
        $subtotal = Money::zero();
        $count = 0;
        foreach ($items as $item) {
            $line = $this->line($item);
            if ($line['line_total'] !== null && $line['problem'] === null) {
                $subtotal = $subtotal->add($line['line_total']);
            }
            $count += $line['quantity'];
            $lines[] = $line;
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'count' => $count,
            'blocked' => collect($lines)->contains(fn (array $line): bool => $line['problem'] !== null),
            'empty' => $lines === [],
        ];
    }

    /** Remove the cart and its lines (after an order is placed). */
    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->delete();
    }

    /** Problem message of a line for the customer. */
    public static function problemMessage(?string $problem, ?int $available = null): ?string
    {
        return match ($problem) {
            null => null,
            self::PROBLEM_NO_PRICE => __('checkout.cart_item_no_price'),
            self::PROBLEM_VARIANT_REQUIRED => __('checkout.cart_item_variant_required'),
            self::PROBLEM_OUT_OF_STOCK => __('checkout.cart_item_out_of_stock'),
            self::PROBLEM_INSUFFICIENT_STOCK => __('checkout.cart_item_insufficient_stock', ['available' => (int) $available]),
            default => __('checkout.cart_item_unpublished'),
        };
    }

    /**
     * URL of a public checkout route (`cart.index`, `checkout.show`, `order.show`…) in the current language:
     * `shop.*` for the base language, `localized.shop.*` with the locale otherwise.
     *
     * @param  array<string, mixed>|string|int  $parameters
     */
    public static function route(string $name, array|string|int $parameters = []): string
    {
        $locale = app()->getLocale();
        $parameters = is_array($parameters) ? $parameters : [$parameters];
        if ($locale !== Locale::baseCode() && Route::has('localized.shop.'.$name)) {
            return route('localized.shop.'.$name, ['locale' => $locale] + $parameters);
        }

        return route('shop.'.$name, $parameters);
    }

    /** The base-language row of a product when it is publicly visible; null otherwise. */
    public function commerceRow(Product $product): ?Product
    {
        $base = $product->isBaseLocale() ? $product : $product->baseTranslation();

        return $base !== null && $this->isLive($base) ? $base : null;
    }

    /**
     * Current price, stock and problem of one line.
     *
     * @return array{item: CartItem, product: Product|null, variant: ProductVariant|null, title: string, variant_name: string|null, sku: string|null, url: string|null, quantity: int, unit_price: Money|null, line_total: Money|null, available: int|null, problem: string|null}
     */
    private function line(CartItem $item): array
    {
        $product = $item->product;
        $variant = $item->variant_id === null ? null : $item->variant;
        $localized = $product === null ? null : $this->localizedRow($product);
        $line = [
            'item' => $item,
            'product' => $product,
            'variant' => $variant,
            'title' => $localized?->title ?? $product?->title ?? __('checkout.cart_item_unpublished'),
            'variant_name' => $variant?->name,
            'sku' => $variant?->sku ?: $product?->sku,
            'url' => $localized?->url(),
            'quantity' => (int) $item->quantity,
            'unit_price' => null,
            'line_total' => null,
            'available' => null,
            'problem' => null,
        ];

        if ($product === null || ! $product->isBaseLocale() || ! $this->isLive($product) || ($item->variant_id !== null && ($variant === null || $variant->product_id !== $product->id))) {
            return ['problem' => self::PROBLEM_UNPUBLISHED, 'url' => null] + $line;
        }
        if ($product->has_variants && $variant === null) {
            return ['problem' => self::PROBLEM_VARIANT_REQUIRED] + $line;
        }
        if (! $product->has_variants && $variant !== null) {
            return ['problem' => self::PROBLEM_UNPUBLISHED] + $line;
        }

        $price = $variant !== null ? $variant->price_amount : $product->price_amount;
        $line['available'] = $this->available($product, $variant);
        if ($price === null) {
            return ['problem' => self::PROBLEM_NO_PRICE] + $line;
        }
        $line['unit_price'] = $price;
        $line['line_total'] = $price->multiply($line['quantity']);
        if ($line['available'] !== null && $line['available'] <= 0) {
            return ['problem' => self::PROBLEM_OUT_OF_STOCK] + $line;
        }
        if ($line['available'] !== null && $line['quantity'] > $line['available']) {
            return ['problem' => self::PROBLEM_INSUFFICIENT_STOCK] + $line;
        }

        return $line;
    }

    /** Units that can still be sold; null when stock is not tracked or backorders are allowed. */
    public function available(Product $product, ?ProductVariant $variant): ?int
    {
        if (! $product->track_stock || $product->allow_backorder) {
            return null;
        }

        return max(0, (int) ($variant !== null ? $variant->stock : $product->stock));
    }

    /** Published row of the product group in the current language (title and URL), else the base row. */
    private function localizedRow(Product $base): Product
    {
        $locale = app()->getLocale();
        if ($base->locale === $locale) {
            return $base;
        }
        $translation = $base->translations->first(fn (Product $row): bool => $row->locale === $locale && $row->isPublished());

        return $translation ?? $base;
    }

    private function isLive(Product $product): bool
    {
        return $product->isPublished();
    }

    /** @throws ValidationException */
    private function assertPurchasable(Product $commerce, ?ProductVariant $variant, int $quantity): void
    {
        $price = $variant !== null ? $variant->price_amount : $commerce->price_amount;
        if ($price === null) {
            throw ValidationException::withMessages(['product_id' => __('checkout.cart_item_no_price')]);
        }
        $this->assertStock($commerce, $variant, $quantity);
    }

    /** @throws ValidationException */
    private function assertStock(Product $commerce, ?ProductVariant $variant, int $quantity): void
    {
        $available = $this->available($commerce, $variant);
        if ($available === null) {
            return;
        }
        if ($available <= 0) {
            throw ValidationException::withMessages(['quantity' => __('checkout.cart_item_out_of_stock')]);
        }
        if ($quantity > $available) {
            throw ValidationException::withMessages(['quantity' => __('checkout.cart_item_insufficient_stock', ['available' => $available])]);
        }
    }

    /** @throws ValidationException */
    private function assertQuantity(int $quantity): int
    {
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw ValidationException::withMessages(['quantity' => __('checkout.cart_quantity_invalid', ['max' => self::MAX_QUANTITY])]);
        }

        return $quantity;
    }

    private function assertOwned(Cart $cart, CartItem $item): void
    {
        abort_unless((int) $item->cart_id === (int) $cart->id, 404);
    }

    /** Every change keeps the cart alive for another cart_days. */
    private function touch(Cart $cart): void
    {
        $cart->forceFill(['expires_at' => $this->expiry()])->save();
    }

    private function expiry(): Carbon
    {
        return now()->addDays($this->days());
    }

    private function days(): int
    {
        return max(1, (int) config('cms.shop.checkout.cart_days', 30));
    }
}
