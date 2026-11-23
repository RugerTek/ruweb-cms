<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\Shop\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Public guest cart (PLAN.md E3.2 step 1), only while the shop mode is `checkout` (EnsureShopCheckout). Every
 * change answers with a redirect to the cart page, which shows the outcome or the error, and refreshes the
 * `ruweb_cart` cookie. Route parameters are read by name: the same actions also run under `{locale}`.
 */
class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function index(Request $request): Response
    {
        $cart = $this->carts->current($request);

        return response()
            ->view('shop.cart', ['cart' => $this->carts->summary($cart)])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }

    public function add(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'quantity' => ['nullable', 'integer'],
        ]);
        $product = Product::query()->find((int) $data['product_id']);
        if ($product === null || $this->carts->commerceRow($product) === null) {
            return $this->toCart()->withErrors(['product_id' => __('checkout.cart_item_unpublished')]);
        }
        $cart = $this->carts->currentOrCreate($request);

        try {
            $this->carts->add($cart, $product, isset($data['variant_id']) ? (int) $data['variant_id'] : null, (int) ($data['quantity'] ?? 1));
        } catch (ValidationException $exception) {
            return $this->toCart()->withErrors($exception->errors())->withCookie($this->carts->cookie($cart));
        }

        return $this->toCart()->with('status', __('checkout.cart_added'))->withCookie($this->carts->cookie($cart));
    }

    public function update(Request $request): RedirectResponse
    {
        [$cart, $item] = $this->line($request);
        $data = $request->validate(['quantity' => ['required', 'integer']]);

        try {
            $this->carts->update($cart, $item, (int) $data['quantity']);
        } catch (ValidationException $exception) {
            return $this->toCart()->withErrors($exception->errors());
        }

        return $this->toCart()->with('status', __('checkout.cart_updated'))->withCookie($this->carts->cookie($cart));
    }

    public function remove(Request $request): RedirectResponse
    {
        [$cart, $item] = $this->line($request);
        $this->carts->remove($cart, $item);

        return $this->toCart()->with('status', __('checkout.cart_removed'))->withCookie($this->carts->cookie($cart));
    }

    /** @return array{0: Cart, 1: CartItem} the line of the URL, only within the visitor's own cart */
    private function line(Request $request): array
    {
        $cart = $this->carts->current($request);
        abort_if($cart === null, 404);
        $item = $cart->items()->whereKey((int) $request->route('item'))->first();
        abort_if($item === null, 404);

        return [$cart, $item];
    }

    private function toCart(): RedirectResponse
    {
        return redirect()->to(CartService::route('cart.index'));
    }
}
