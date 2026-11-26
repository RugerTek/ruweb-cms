# Shop module: cart, checkout and orders

Selling online on top of the catalog ([catalog.md](catalog.md)): a guest cart, one checkout form, an order page
reached only by a secret link, and the orders panel. It runs only while the effective shop mode is `checkout`;
payments are in [payments.md](payments.md) and delivery options in [shipping.md](shipping.md). This doc owns the
purchase flow, the order and payment states, stock, anti-spam, the order page and the scheduled clean-up.

There are no customer accounts: every buyer is a guest. The decisions behind the flow (fixed price per zone, stock
taken when the payment is confirmed, no `shop_manager` role, no electronic invoice) are recorded in `PLAN.md`
(stage 3), which is not part of the docs.

## Where things live

| Piece | Where |
|---|---|
| Public routes | `routes/shop-checkout.php`, required from `routes/web.php` before `routes/shop.php` (so `/productos/carrito` never reaches the product route), in the base group (`shop.*`) and under `{locale}` (`localized.shop.*`). Its existence is what `ShopModule::checkoutAvailable()` checks. |
| Panel routes | `routes/shop-checkout-admin.php`, grouped from `routes/admin.php` behind `EnsureShopCatalog` |
| MCP REST mirror | `routes/mcp-shop-checkout.php` ([../operations/mcp.md](../operations/mcp.md#orders-and-payments)) |
| Cart | `app/Services/Shop/CartService.php`, `app/Http/Controllers/Shop/CartController.php`, `app/View/Composers/CartComposer.php` (header counter) |
| Checkout | `app/Services/Shop/CheckoutService.php`, `app/Http/Controllers/Shop/CheckoutController.php`, `app/Http/Requests/Public/CheckoutRequest.php` |
| Order page | `app/Http/Controllers/Shop/OrderPageController.php`, `resources/views/shop/order.blade.php` |
| Order and payment writes | `app/Services/Shop/OrderOperations.php`, `app/Services/Shop/PaymentOperations.php`, `app/Services/Shop/StockLedger.php`, `app/Policies/OrderPolicy.php` |
| Emails | `app/Listeners/Shop/SendOrderMails.php`, `app/Mail/Shop/`, `resources/views/mail/shop/` |
| Texts | `lang/es/checkout.php`, `lang/en/checkout.php` (parity rule: [../admin/translations.md](../admin/translations.md)) |
| Tables | `database/migrations/2026_11_24_000001_create_checkout_tables.php` (always created, like every shop table) and `database/migrations/2026_11_24_000002_add_checkout_settings.php` (settings and the three payment methods, created disabled and empty) |

## Turning selling on

Settings > Shop offers **Catalog + selling** only when `CheckoutReadiness` (`app/Services/Shop/CheckoutReadiness.php`)
reports nothing missing; `ShopModuleOperations::setMode('checkout')` refuses it otherwise (panel, MCP
`settings_update` and the installer alike). The three requirements (payment method, delivery, orders email; sending
email is not one, see [Selling without email](#sin-email)), each shown with a link to the screen that fixes
it, are listed in [catalog.md](catalog.md#sell-online).

While selling, Admin > Payments and shipping and MCP cannot remove the last ready payment method or the last
delivery option (`CheckoutReadiness::guard()`): the write is rolled back with a message.

Going back to `catalog` or `off` deletes nothing. Settings > Shop says how many orders are still open (pending, or
paid and not delivered) before confirming. Afterwards:

| Surface | With the mode below `checkout` |
|---|---|
| Cart, checkout, "Add to cart" button, header counter | Gone (`EnsureShopCheckout`: 404, as if they never existed) |
| Order page by token, its "try again" button | Kept while the catalog is on |
| Admin > Orders, payments panel, MCP `orders_*` / `payments_*` | Kept while the catalog is on and orders exist |
| `POST /webhooks/bancard`, `shop:expire-payments` | Always, whatever the mode: payments in flight still settle |

## The purchase flow

1. **Add to cart.** The product page shows "Add to cart" only in `checkout` and never in the editor preview, with a
   variant choice and a quantity; it is disabled when the product (or the chosen variant) has no price or no stock.
   The button is a convenience: the cart checks everything again.
2. **Cart** (`/productos/carrito`, `shop.cart.*`). A `carts` row behind the `ruweb_cart` cookie (64 characters,
   `cms.shop.checkout.cart_days` days, `HttpOnly`, `SameSite=Lax`). Lines keep only product, variant and quantity
   (1 to `CartService::MAX_QUANTITY`); price, stock and publication are read again from the product's base-language
   row every time the cart is shown. A line can turn unavailable (`unpublished`, `no_price`, `variant_required`,
   `out_of_stock`, `insufficient_stock`) and then blocks the checkout until the customer fixes it. The header shows
   a counter only in `checkout`; the composer does not query the database without a cart cookie. Cart writes are
   limited by `throttle:cart` (60 per minute per IP).
3. **Checkout** (`/productos/checkout`, `shop.checkout.show` / `shop.checkout.store`). One form: name, email, phone,
   delivery (store pickup, or a zone and then address, city, department and reference), notes, payment method (only
   enabled and complete ones, in panel order) and the purchase policies page chosen in the panel
   (`shop_terms_page_id`).
4. **Place the order** (`CheckoutService::place()`, one transaction with the cart row locked, so a double submit
   finds the cart gone). It revalidates the cart and the chosen delivery and payment method, freezes each line
   (title, variant, SKU, unit price, quantity), the zone name and price and the totals (total = lines + shipping),
   numbers the order (`ORD-2026-000001`: `order_prefix`, year, sequence; unique index with retry), creates one
   pending payment attempt, empties the cart and dispatches `OrderPlaced` after commit. **It does not touch
   stock.**
5. **Order page** (below), with the payment block: instructions for manual methods, or the Bancard iframe.
6. **Payment confirmed** (a verified Bancard notification, or the administrator for manual methods): the order
   becomes `paid`, stock is taken and `OrderPaid` sends the customer email.
7. **Fulfil**: the team marks the order delivered (or picked up) in Admin > Orders.

## Anti-spam

The checkout carries the full layer of `AGENTS.md` rule 5 ([../content/forms.md](../content/forms.md)): `@csrf`,
`<x-forms.honeypot>`, `<x-forms.recaptcha action="checkout">`, `throttle:forms` on the POST and a minimum fill time
through a `FormToken` issued for page `0` and form `checkout` (the checkout is not a CMS page).

Two differences with other public forms, both in `CheckoutRequest`:

- A tripped honeypot answers like a success and creates nothing.
- A token younger than the minimum fill time (3 s) is a **visible** error (`checkout.error_too_fast`), not a silent
  drop: a real customer whose script did not run must not lose the order.

The order stores the IP, user agent and reCAPTCHA score.

## The order page

`/productos/pedido/{token}` (`shop.order.show`). The token is 64 random letters and digits; the database keeps its
sha256 (`access_token_hash`, unique) and an encrypted copy so the panel can resend the link. The page:

- answers 404 for an unknown token; an order is **never** looked up by its number;
- sends `X-Robots-Tag: noindex, nofollow, noarchive`, `Referrer-Policy: no-referrer` and `Cache-Control: no-store,
  private`;
- shows the summary, the delivery and the state, and a payment block chosen by the latest attempt:

| Block | When | Shows |
|---|---|---|
| `collect` | Pending order, pending attempt | Manual method: its public instructions (bank details, cash-on-delivery text). Bancard: the iframe ([payments.md](payments.md#bancard-vpos-20)) |
| `confirming` | Back from the payment form (`?status=`) and the outcome is not known yet | "We are confirming your payment"; the page reloads every 5 s |
| `failed` / `expired` | The online attempt was rejected or abandoned | The outcome and **Try again** (`shop.order.pay`, POST, `throttle:forms`), which opens a new attempt with a new Bancard `shop_process_id` |
| `confirmed` | Paid | Date and time, order number, amount, the result description and the authorization code |
| `refunded` / `none` | Refunded, or the order no longer waits for a payment (cancelled) | The state only |

The `?status=` Bancard appends on the way back is **never trusted**. If the notification has not arrived yet, the
page asks Bancard itself (`get_confirmation`, at most once per attempt every 30 s because the page reloads) and
applies a verified answer; otherwise it keeps saying "confirming" and the webhook or the scheduler settles it.

The page never shows what Bancard forbids: `response_code`, `extended_response_description` or
`security_information`.

## States

Order (`Order::TRANSITIONS`, `app/Models/Order.php`):

```text
pending   → paid | cancelled
paid      → fulfilled | cancelled | refunded
fulfilled → refunded
cancelled, refunded: final
```

Payment attempt (`Payment::TRANSITIONS`): `pending → confirmed | failed | expired`, `confirmed → refunded`. One row
per attempt: a retry after `failed` or `expired` is a new row.

Every transition is idempotent: repeating the one already done returns the record unchanged, without side effects
or events; any move the state does not allow is a validation error. `OrderOperations` and `PaymentOperations` lock
the order and then the attempt (`lockForUpdate`) and dispatch events after commit, once.

| Event | Dispatched when | Emails (queued, only with `mail_enabled`; see [Selling without email](#sin-email)) |
|---|---|---|
| `OrderPlaced` | Order placed | Customer (with the order link) and the orders email |
| `OrderPaid` | Order becomes paid | Customer |
| `OrderFulfilled` | Order marked delivered | Customer: on its way or delivered (home delivery), ready or picked up with the pickup address (pickup) |
| `OrderCancelled` | Order cancelled (`admin` or `unpaid`) | Customer |
| `StockShortfall` | A paid order did not fit in stock | Orders email |
| `PaymentFailed` | Attempt failed (`rejected`, `admin`, `amount_mismatch`) | Orders email, only for `amount_mismatch` |

`SendOrderMails` is a subscriber whose methods do not start with `handle` (Laravel's listener discovery would
register `handle*` methods a second time and send every mail twice). Each mail goes out once per order even if its
event repeats, and the order link is built when the mail is rendered, so the token never sits in the queue.
Payment emails for charges that do not belong to a paid order are in
[payments.md](payments.md#stray-charges-late-payment-and-another-amount).

<a id="sin-email"></a>

## Selling without email

Email is optional for selling ([../operations/email.md](../operations/email.md)): no panel feature may depend on an
email going out. `CheckoutReadiness` does not ask for it, and while it is not active
(`App\Services\Mail\MailSettings::active()`: switched on and the provider complete):

| Where | What happens |
|---|---|
| Settings > Shop, Admin > Payments and shipping, Admin > Orders | A notice «El envío de emails no está activo»: customers get no confirmation email and the store no notices, orders still appear in Orders; administrators get a link to Admin > Email (`resources/views/admin/shop/_mail_notice.blade.php`) |
| Order page right after placing the order | The order number, the payment block (bank details, cash-on-delivery text or Bancard) and the link, with «Guardá este enlace o anotá el número de pedido…» instead of «También te lo enviamos por email» (`data-order-link-hint="no-mail"`, `mail` otherwise) |
| `SendOrderMails` and the other senders (Meta reconnect warning, contact notifications) | With `mail_enabled` off they skip and log (`Order mail skipped: mail is disabled in settings.`); a failure to queue or send is logged and recorded as the last email error. Nothing is rolled back and no request fails |
| Admin > Orders | Orders, payment confirmation, cancellation and delivery work the same; «resend the customer email» answers that it could not be sent |

Without email the buyer's only way back to the order page is the link they saw after buying, so the page asks them
to keep it; once email works, the panel can resend it from the order.

## Stock

Stock moves only through `StockLedger`, inside the transaction of the operation, with the product and variant rows
locked in id order; `orders.stock_applied_at` makes it idempotent. Only products with `track_stock` move (a variant
follows its product's flag); sales do not create editorial versions.

- **Placing an order does not reserve stock.** The cart and the checkout refuse lines that do not fit at that
  moment, but two customers can buy the last unit at the same time.
- **Confirming the payment takes it.** If a line no longer fits (someone else paid first), the payment is still
  accepted: the order is `paid` with `stock_shortfall` (what was missing), the stock stays at 0 and the orders email
  gets a warning to restock or return the money. A charged payment is never rejected. With `allow_backorder` stock
  may go negative instead.
- **Cancelling a paid order or refunding it gives back** exactly what was taken (using the stored shortfall).
  Failing or expiring an attempt never touches stock.

## Who does what

`OrderPolicy`: every CMS role sees orders, keeps internal notes, resends the customer email and marks orders
delivered; only administrators cancel orders and confirm, fail or refund payments. The panel (Full and Essential
alike) and MCP share the same operations. Admin > Orders lists orders with filters (status, payment method, dates)
and shows items, delivery, every attempt with what the provider reported (summarised: never raw payloads, tokens or
keys), and a flag on orders with a charge to review ([payments.md](payments.md#stray-charges-late-payment-and-another-amount)).

## Scheduled clean-up

Both commands are scheduled in `routes/console.php` and run whatever the shop mode.

| Command | Schedule | What it does |
|---|---|---|
| `shop:expire-payments` | Every 5 minutes, without overlapping | Bancard attempts pending for `bancard_pending_minutes` (10) that reached Bancard: query and apply, or roll back and expire ([payments.md](payments.md#abandoned-attempts-the-10-minute-sweep)). Then `OrderOperations::cancelUnpaid()`: orders with an automatic method (any provider but `manual`) still unpaid after `unpaid_order_hours` (48) are cancelled (`OrderCancelled::REASON_UNPAID`, customer email). Orders with an attempt still open at Bancard wait for the sweep first. Manual orders are never cancelled automatically: the administrator does it. |
| `shop:prune-carts` | Daily | Deletes guest carts past `expires_at` (`cart_days` without activity) with their lines |

Settings of the block, in `config/cms.php` `shop.checkout`: `cart_days` (30), `unpaid_order_hours` (48),
`bancard_pending_minutes` (10), `order_prefix` (`ORD`).

## Snapshots

Orders, their items, addresses and payments travel in snapshots; `carts`, `cart_items` and `payment_events` are
excluded (`SnapshotService::EXCLUDED_TABLES`: transient visitor data and the raw provider audit trail). Bancard keys
travel encrypted but stop working on another domain or `APP_KEY` ([payments.md](payments.md#credentials)).

## Tests

`Tests\TestCase::useCheckout()` turns `checkout` on with ready test methods and a zone; `useFakeBancard()` swaps
Bancard for `tests/Support/FakeBancard.php`. `tests/Support/CheckoutFixtures.php` builds products with stock, carts
and orders, and `tests/Support/FakePaymentProvider.php` is an iframe provider without Bancard. Files:
`CheckoutContractsTest` (tables, routes, readiness, reserved slugs), `CartTest`, `CheckoutFlowTest` (anti-spam,
totals, token, order page), `OrderOperationsTest`, `PaymentOperationsTest`, `OrderMailTest`, `AdminOrdersTest`,
`McpOrdersTest`, and `CheckoutEndToEndTest` (full purchases through the panel and the public pages: a manual payment confirmed by hand up to delivery, Bancard against the fake, and a shop without email that sells and sends nothing); Bancard tests are listed in [payments.md](payments.md#tests).
