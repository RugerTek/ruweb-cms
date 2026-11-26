<?php

namespace Tests\Feature;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Locale;
use App\Models\Product;
use App\Services\Shop\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CheckoutFixtures;
use Tests\TestCase;

/** Guest cart and header icon (PLAN.md E3.2 step 1, track 3A). */
class CartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Locale::updateOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        Locale::updateOrCreate(['code' => 'en'], ['name' => 'English', 'is_base' => false, 'is_active' => true, 'is_public' => true, 'sort_order' => 1]);
        $this->useCheckout();
    }

    public function test_add_change_and_remove_lines_with_a_guest_cookie(): void
    {
        $product = CheckoutFixtures::product(['title' => 'Mate de calabaza', 'price_amount' => 150000, 'stock' => 10]);

        $response = $this->post(route('shop.cart.add'), ['product_id' => $product->id, 'quantity' => 2])
            ->assertRedirect(route('shop.cart.index'))
            ->assertSessionHas('status', __('checkout.cart_added'));
        $cookie = $response->getCookie(CartService::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame(Cart::TOKEN_LENGTH, strlen($cookie->getValue()));
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $cookie->getExpiresTime(), 5);

        $cart = Cart::query()->sole();
        $this->assertSame($cookie->getValue(), $cart->token);
        $this->withCookie(CartService::COOKIE, $cart->token);

        // Adding the same product again sums into the same line.
        $this->post(route('shop.cart.add'), ['product_id' => $product->id])->assertRedirect(route('shop.cart.index'));
        $item = CartItem::query()->sole();
        $this->assertSame(3, $item->quantity);

        $this->get(route('shop.cart.index'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Mate de calabaza')
            ->assertSee('Gs. 450.000')
            ->assertSee('data-go-to-checkout', false);

        $this->patch(route('shop.cart.update', $item->id), ['quantity' => 5])->assertSessionHas('status', __('checkout.cart_updated'));
        $this->assertSame(5, $item->fresh()->quantity);
        $this->patch(route('shop.cart.update', $item->id), ['quantity' => 0])->assertSessionHasErrors('quantity');
        $this->patch(route('shop.cart.update', $item->id), ['quantity' => 11])->assertSessionHasErrors('quantity');
        $this->assertSame(5, $item->fresh()->quantity);

        $this->delete(route('shop.cart.remove', $item->id))->assertSessionHas('status', __('checkout.cart_removed'));
        $this->assertDatabaseCount('cart_items', 0);
        $this->get(route('shop.cart.index'))->assertOk()->assertSee(__('checkout.cart_empty'));
    }

    public function test_lines_of_another_cart_are_not_reachable(): void
    {
        $product = CheckoutFixtures::product();
        $other = CheckoutFixtures::cart([[$product, 1]]);
        $mine = CheckoutFixtures::cart([[$product, 1]]);

        $this->withCookie(CartService::COOKIE, $mine->token);
        $this->patch(route('shop.cart.update', $other->items[0]->id), ['quantity' => 3])->assertNotFound();
        $this->delete(route('shop.cart.remove', $other->items[0]->id))->assertNotFound();
        $this->assertSame(1, $other->items[0]->fresh()->quantity);

        // No cookie at all: nothing to change.
        $this->defaultCookies = [];
        $this->delete(route('shop.cart.remove', $mine->items[0]->id))->assertNotFound();
    }

    public function test_variants_unpublished_unpriced_and_out_of_stock_products_cannot_be_added(): void
    {
        $withVariants = CheckoutFixtures::productWithVariants();
        $small = $withVariants->variants[0];
        $draft = CheckoutFixtures::product(['status' => 'draft', 'published_at' => null]);
        $unpriced = CheckoutFixtures::product(['price_amount' => null]);
        $soldOut = CheckoutFixtures::product(['stock' => 0]);
        $scarce = CheckoutFixtures::product(['stock' => 2]);
        $backorder = CheckoutFixtures::product(['stock' => 0, 'allow_backorder' => true]);

        $this->post(route('shop.cart.add'), ['product_id' => $withVariants->id])->assertSessionHasErrors(['variant_id' => __('checkout.cart_item_variant_required')]);
        $this->post(route('shop.cart.add'), ['product_id' => $draft->id])->assertSessionHasErrors(['product_id' => __('checkout.cart_item_unpublished')]);
        $this->post(route('shop.cart.add'), ['product_id' => 999999])->assertSessionHasErrors('product_id');
        $this->post(route('shop.cart.add'), ['product_id' => $unpriced->id])->assertSessionHasErrors(['product_id' => __('checkout.cart_item_no_price')]);
        $this->post(route('shop.cart.add'), ['product_id' => $soldOut->id])->assertSessionHasErrors(['quantity' => __('checkout.cart_item_out_of_stock')]);
        $this->post(route('shop.cart.add'), ['product_id' => $scarce->id, 'quantity' => 3])
            ->assertSessionHasErrors(['quantity' => __('checkout.cart_item_insufficient_stock', ['available' => 2])]);
        $this->assertDatabaseCount('cart_items', 0);

        $this->post(route('shop.cart.add'), ['product_id' => $withVariants->id, 'variant_id' => $small->id])->assertSessionHasNoErrors();
        $this->post(route('shop.cart.add'), ['product_id' => $backorder->id, 'quantity' => 4])->assertSessionHasNoErrors();
        $this->assertSame([$small->id, null], CartItem::query()->orderBy('id')->pluck('variant_id')->all());
    }

    public function test_lines_that_turned_unavailable_are_marked_and_block_the_checkout(): void
    {
        $fine = CheckoutFixtures::product(['title' => 'Bombilla']);
        $unpublished = CheckoutFixtures::product(['title' => 'Termo retirado']);
        $unpriced = CheckoutFixtures::product(['title' => 'Yerba sin precio']);
        $scarce = CheckoutFixtures::product(['title' => 'Mate escaso', 'stock' => 5]);
        $cart = CheckoutFixtures::cart([[$fine, 1], [$unpublished, 1], [$unpriced, 1], [$scarce, 4]]);
        $this->withCookie(CartService::COOKIE, $cart->token);

        $unpublished->update(['status' => 'draft']);
        $unpriced->update(['price_amount' => null]);
        $scarce->update(['stock' => 2]);

        $lines = collect(app(CartService::class)->summary($cart->fresh())['lines'])->keyBy(fn (array $line) => $line['product']->id);
        $this->assertNull($lines[$fine->id]['problem']);
        $this->assertSame(CartService::PROBLEM_UNPUBLISHED, $lines[$unpublished->id]['problem']);
        $this->assertSame(CartService::PROBLEM_NO_PRICE, $lines[$unpriced->id]['problem']);
        $this->assertSame(CartService::PROBLEM_INSUFFICIENT_STOCK, $lines[$scarce->id]['problem']);

        $this->get(route('shop.cart.index'))
            ->assertOk()
            ->assertSee('data-cart-problem="unpublished"', false)
            ->assertSee(__('checkout.cart_item_no_price'))
            ->assertSee(__('checkout.cart_item_insufficient_stock', ['available' => 2]))
            ->assertSee('data-cart-blocked', false)
            ->assertDontSee('data-go-to-checkout', false);
        $this->get(route('shop.checkout.show'))->assertRedirect(route('shop.cart.index'))->assertSessionHasErrors('cart');

        $scarce->update(['stock' => 0]);
        $this->assertSame(CartService::PROBLEM_OUT_OF_STOCK, collect(app(CartService::class)->summary($cart->fresh())['lines'])->firstWhere('product.id', $scarce->id)['problem']);
    }

    public function test_prices_are_recalculated_on_every_view(): void
    {
        $product = CheckoutFixtures::product(['title' => 'Termo', 'price_amount' => 200000]);
        $cart = CheckoutFixtures::cart([[$product, 2]]);
        $this->withCookie(CartService::COOKIE, $cart->token);

        $this->get(route('shop.cart.index'))->assertSee('Gs. 400.000');
        $product->update(['price_amount' => 180000]);
        $this->get(route('shop.cart.index'))->assertSee('Gs. 360.000')->assertDontSee('Gs. 400.000');
        $this->assertSame(360000, app(CartService::class)->summary($cart->fresh())['subtotal']->amount);
    }

    public function test_translated_products_go_to_the_base_row_and_show_the_visitor_language(): void
    {
        $base = CheckoutFixtures::product(['title' => 'Termo', 'slug' => 'termo']);
        $translation = Product::create(['locale' => 'en', 'translation_group_id' => $base->translation_group_id, 'title' => 'Thermos', 'slug' => 'thermos', 'status' => 'published', 'published_at' => now()->subMinute()]);

        $this->post(route('localized.shop.cart.add', ['locale' => 'en']), ['product_id' => $translation->id])
            ->assertRedirect(route('localized.shop.cart.index', ['locale' => 'en']));
        $cart = Cart::query()->sole();
        $this->assertSame($base->id, $cart->items()->sole()->product_id);

        $this->withCookie(CartService::COOKIE, $cart->token);
        $this->get('/en/productos/carrito')->assertOk()->assertSee('Thermos')->assertSee('href="/en/productos/thermos"', false);
        $this->get('/productos/carrito')->assertOk()->assertSee('Termo');
    }

    public function test_cart_and_checkout_exist_only_while_selling_online(): void
    {
        $cart = CheckoutFixtures::cart([[CheckoutFixtures::product(), 1]]);
        $this->withCookie(CartService::COOKIE, $cart->token);

        $this->useShop('catalog');
        $this->get('/productos/carrito')->assertNotFound();
        $this->get('/productos/checkout')->assertNotFound();
        $this->post(route('shop.cart.add'), ['product_id' => CheckoutFixtures::product()->id])->assertNotFound();

        $this->useShop('checkout');
        $this->get('/productos/carrito')->assertOk();
    }

    public function test_product_page_offers_add_to_cart_only_while_selling_online_and_not_in_the_preview(): void
    {
        $product = CheckoutFixtures::product(['title' => 'Termo acero', 'slug' => 'termo-acero', 'stock' => 3]);
        $form = 'data-add-to-cart';

        $html = $this->get('/productos/termo-acero')->assertOk()
            ->assertSee($form, false)
            ->assertSee('action="'.route('shop.cart.add').'"', false)
            ->assertSee('name="product_id" value="'.$product->id.'"', false)
            ->assertSee('name="quantity" value="1" min="1" max="'.CartService::MAX_QUANTITY.'"', false)
            ->assertDontSee('name="variant_id"', false)
            ->assertDontSee('data-add-to-cart-unavailable', false)
            ->getContent();
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?=[\s>])/', $this->addToCartForm($html));

        // The form posts what the cart expects: the product and the quantity.
        $this->post(route('shop.cart.add'), ['product_id' => $product->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->assertSame(2, CartItem::query()->sole()->quantity);

        // The editor preview of the product never offers to buy.
        $preview = view($product->previewView(), $product->previewData() + ['isEditorPreview' => true])->render();
        $this->assertStringNotContainsString($form, $preview);

        // Catalog only: no button.
        $this->useShop('catalog');
        $this->get('/productos/termo-acero')->assertOk()->assertDontSee($form, false);
    }

    public function test_add_to_cart_is_hidden_without_price_disabled_without_stock_and_lists_buyable_variants(): void
    {
        CheckoutFixtures::product(['title' => 'Sin precio', 'slug' => 'sin-precio', 'price_amount' => null]);
        CheckoutFixtures::product(['title' => 'Agotado', 'slug' => 'agotado', 'stock' => 0]);
        CheckoutFixtures::product(['title' => 'Por encargo', 'slug' => 'por-encargo', 'stock' => 0, 'allow_backorder' => true]);
        $withVariants = CheckoutFixtures::productWithVariants([
            ['name' => 'Talle S', 'price_amount' => 90000, 'stock' => 5],
            ['name' => 'Talle M', 'price_amount' => 92000, 'stock' => 0],
            ['name' => 'Talle L', 'price_amount' => null, 'stock' => 5],
        ], ['title' => 'Remera', 'slug' => 'remera']);
        [$small, $medium, $large] = $withVariants->variants->all();

        // Without a price there is nothing to buy: no form, button or quantity, only how to get the price.
        $this->get('/productos/sin-precio')->assertOk()
            ->assertSee('data-product-no-price', false)
            ->assertSee('Consultá el precio con el comercio')
            ->assertDontSee('data-add-to-cart', false)
            ->assertDontSee('name="quantity"', false)
            ->assertDontSee(__('checkout.add_to_cart'));

        CheckoutFixtures::productWithVariants([['name' => 'Único', 'price_amount' => null, 'stock' => 5]], ['title' => 'Variantes sin precio', 'slug' => 'variantes-sin-precio']);
        $this->get('/productos/variantes-sin-precio')->assertOk()
            ->assertSee('data-product-no-price', false)
            ->assertDontSee('name="variant_id"', false);

        $soldOut = $this->addToCartForm($this->get('/productos/agotado')->assertOk()->assertDontSee('data-product-no-price', false)->getContent());
        $this->assertStringContainsString('type="submit" disabled', $soldOut);
        $this->assertStringContainsString(__('checkout.cart_item_out_of_stock'), $soldOut);

        $backorder = $this->addToCartForm($this->get('/productos/por-encargo')->assertOk()->getContent());
        $this->assertDoesNotMatchRegularExpression('/\sdisabled(?=[\s>])/', $backorder);

        $variants = $this->addToCartForm($this->get('/productos/remera?variante='.$small->id)->assertOk()->getContent());
        $this->assertStringContainsString('name="variant_id" required', $variants);
        $this->assertMatchesRegularExpression('/<option value="'.$small->id.'"\s+selected\s*>/', $variants);
        $this->assertMatchesRegularExpression('/<option value="'.$medium->id.'"[^>]*disabled/', $variants);
        $this->assertMatchesRegularExpression('/<option value="'.$large->id.'"[^>]*disabled/', $variants);
        $this->assertStringNotContainsString('type="submit" disabled', $variants);

        $this->post(route('shop.cart.add'), ['product_id' => $withVariants->id, 'variant_id' => $small->id, 'quantity' => 2])->assertSessionHasNoErrors();
        $this->assertSame([$small->id, 2], [CartItem::query()->sole()->variant_id, CartItem::query()->sole()->quantity]);

        // Every variant unavailable: the whole form is disabled.
        $small->update(['stock' => 0]);
        $none = $this->addToCartForm($this->get('/productos/remera')->assertOk()->getContent());
        $this->assertStringContainsString('type="submit" disabled', $none);
        $this->assertStringContainsString('data-add-to-cart-unavailable', $none);
    }

    /** The add-to-cart form of a product page. */
    private function addToCartForm(string $html): string
    {
        $start = strpos($html, 'data-add-to-cart');
        $this->assertNotFalse($start, 'The page has no add-to-cart form.');

        return substr($html, $start, strpos($html, '</form>', $start) - $start);
    }

    public function test_reserved_slugs_reach_the_cart_and_not_a_product(): void
    {
        // Written straight to the database: ProductInput already refuses these slugs (CheckoutContractsTest).
        CheckoutFixtures::product(['title' => 'Carrito de bebé', 'slug' => 'carrito']);

        $this->get('/productos/carrito')->assertOk()->assertSee(__('checkout.cart_title'))->assertDontSee('Carrito de bebé');
    }

    public function test_header_shows_the_cart_count_only_while_selling_online(): void
    {
        $product = CheckoutFixtures::product();
        $cart = CheckoutFixtures::cart([[$product, 3]]);

        // Selling online without a cart cookie: icon without counter, and no cart query.
        DB::enableQueryLog();
        $this->get('/productos')->assertOk()->assertSee('data-header-cart', false)->assertDontSee('data-cart-count', false);
        $this->assertFalse(collect(DB::getQueryLog())->contains(fn (array $query) => str_contains($query['query'], '"carts"') || str_contains($query['query'], '"cart_items"')));
        DB::flushQueryLog();

        $this->withCookie(CartService::COOKIE, $cart->token);
        $this->get('/productos')->assertOk()->assertSee('data-cart-count', false)->assertSee('>3</span>', false)
            ->assertSee('href="'.route('shop.cart.index').'"', false);

        // Catalog only: no icon, and the cookie is not even read.
        $this->useShop('catalog');
        DB::flushQueryLog();
        $this->get('/productos')->assertOk()->assertDontSee('data-header-cart', false);
        $this->assertFalse(collect(DB::getQueryLog())->contains(fn (array $query) => str_contains($query['query'], '"carts"')));
        DB::disableQueryLog();
    }
}
