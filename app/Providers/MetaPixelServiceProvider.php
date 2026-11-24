<?php

namespace App\Providers;

use App\Http\Controllers\ShopController;
use App\Listeners\Marketing\MetaPixelOrderEvents;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Marketing\MetaPixel;
use App\Services\Marketing\MetaPixelContent;
use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;
use Throwable;

/**
 * Meta Pixel connector runtime (App\Services\Marketing\MetaPixel, docs/operations/meta-pixel.md): one MetaPixel per
 * request (it collects the browser events of the page), the order listeners, and the view composers that start
 * ViewContent (`shop.show`), InitiateCheckout (`shop.checkout`) and the browser Purchase (`shop.order`). The `_fbp`
 * and `_fbc` cookies are written by Meta's script without Laravel's encryption, so they are left out of it.
 */
class MetaPixelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(MetaPixel::class);
    }

    public function boot(): void
    {
        EncryptCookies::except(['_fbp', '_fbc']);

        Event::subscribe(MetaPixelOrderEvents::class);

        View::composer('shop.show', function (ViewInstance $view) {
            $this->safely('ViewContent', function (MetaPixel $pixel) use ($view) {
                $product = $view->getData()['product'] ?? null;
                if ($product instanceof Product) {
                    $pixel->track('ViewContent', fn () => MetaPixelContent::forProduct($product, $this->requestedVariant($product)));
                }
            });
        });

        View::composer('shop.checkout', function (ViewInstance $view) {
            $this->safely('InitiateCheckout', function (MetaPixel $pixel) use ($view) {
                $cart = $view->getData()['cart'] ?? null;
                if (is_array($cart) && ! ($cart['empty'] ?? true)) {
                    $pixel->track('InitiateCheckout', fn () => MetaPixelContent::forCart($cart));
                }
            });
        });

        View::composer('shop.order', function (ViewInstance $view) {
            $this->safely('Purchase', function (MetaPixel $pixel) use ($view) {
                $order = $view->getData()['order'] ?? null;
                if ($order instanceof Order) {
                    $pixel->purchaseInBrowser($order);
                }
            });
        });
    }

    /** The variant chosen in the URL (`?variante=`), when it belongs to the product the feed lists. */
    private function requestedVariant(Product $product): ?ProductVariant
    {
        $id = request()->query(ShopController::VARIANT_QUERY);
        if (! is_scalar($id) || ! ctype_digit((string) $id)) {
            return null;
        }

        return MetaPixelContent::catalogProduct($product)->variants()->whereKey((int) $id)->first();
    }

    /** A composer never breaks the page: errors are logged by MetaPixel without personal data. */
    private function safely(string $event, Closure $callback): void
    {
        $pixel = $this->app->make(MetaPixel::class);
        try {
            $callback($pixel);
        } catch (Throwable $e) {
            $pixel->logFailure($event, $e);
        }
    }
}
