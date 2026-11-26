# Shop module: payments

How an order gets paid: the `PaymentProvider` contract, the manual methods (bank transfer, cash on delivery) and
Bancard vPOS 2.0 (cards, Paraguay). This doc owns the provider contract, Bancard's protocol as Ruweb uses it, what
happens with charges that do not belong to a paid order, the development fake and the certification path. The
purchase flow, order states and stock are in [checkout.md](checkout.md).

## Payment methods

Three rows of `payment_methods`, created **disabled and empty** by
`database/migrations/2026_11_24_000002_add_checkout_settings.php` (site data, never fake defaults):

| Key | Provider | Needs before it can be turned on |
|---|---|---|
| `bank_transfer` | `manual` | `bank_name`, `account_holder`, `account_number` (optional: `account_type`, `document_number`, `alias`, `instructions`) |
| `cod` (cash on delivery) | `manual` | `instructions` |
| `bancard` | `bancard` | The key pair of the active environment, readable on this site and saved under this site's origin |

The administrator prepares them in **Admin > Payments and shipping** (`admin.shop-payments.edit`, administrators only,
available with the catalog on so everything is ready before selling is turned on): enable, order, public fields,
Bancard keys and environment. MCP `payment_methods_update` does the same except switching the Bancard environment
([../operations/mcp.md](../operations/mcp.md#orders-and-payments)). Both go through
`App\Services\Shop\PaymentMethodOperations`: an enabled method must stay complete (the checkout would silently leave
an incomplete one out), and while selling no write may leave the checkout without a payment method
(`CheckoutReadiness::guard()`).

The checkout offers the methods that are enabled **and** ready (`PaymentProviderRegistry::available()`), in panel
order.

## The provider contract

`App\Contracts\Shop\PaymentProvider` (`app/Contracts/Shop/PaymentProvider.php`), registered in
`config/cms.php` → `shop.payment_providers` (key => class; the key is the `provider` column of `payment_methods` and
`payments`) and resolved through `App\Shop\Payments\PaymentProviderRegistry`. A stored method whose provider is not
registered is never ready (`provider_unavailable`).

```php
public function key(): string;                                              // 'manual', 'bancard'
public function isReady(PaymentMethod $method): bool;                       // missing() is empty
public function missing(PaymentMethod $method): array;                      // snake_case ids: 'bank_name', 'credentials'…
public function start(Payment $payment, PaymentUrls $urls): PaymentStart;   // instructions, or an iframe
public function parseNotification(Request $request): ?PaymentNotification;  // null = monitoring ping / none
public function verify(PaymentNotification $notification, Payment $payment): bool;
public function query(Payment $payment): ?PaymentNotification;              // null = nothing paid yet
public function cancel(Payment $payment): CancelResult;                     // reverse at the service
```

Rules every provider follows:

- **Providers talk and translate; they never change orders, payments or stock.** What they report is applied by
  `App\Services\Shop\PaymentOperations` (through `StrayChargeOperations::apply()` for online answers, below).
- `parseNotification()` does not authenticate: callers always call `verify()`, which checks with the credentials
  of the environment **the attempt was created in**. Amount and currency are compared by `PaymentOperations`.
- No credentials in exceptions, logs, events or the returned DTOs.

DTOs in `app/Shop/Payments/`: `PaymentUrls` (return and cancel URL), `PaymentStart` (`instructions` with the public
fields, or `iframe` with `processId` and `scriptUrl`), `PaymentNotification` (reference, approved, amount, response
code and description, authorization and ticket numbers, raw payload) and `CancelResult` (`ok`, `manualRequired`,
`code`).

A new gateway is a class implementing the contract, an entry in `cms.shop.payment_providers`, a `payment_methods` row
created by a migration (disabled), its panel fields, `lang/*/checkout.php` keys and tests with an HTTP fake.

## Manual methods

`App\Shop\Payments\ManualPaymentProvider` (`app/Shop/Payments/ManualPaymentProvider.php`, key `manual`): no
service behind it. `start()` returns the method's public fields for the order page and the emails; nothing ever
notifies; `verify()` always says no. The administrator confirms the payment when the money arrives
(Admin > Orders → Confirm, or `payments_confirm`), fails it, or cancels the order. Refunding a manual payment is
always by hand (`manual_refund`): "Mark as refunded by hand".

## Bancard vPOS 2.0

Card payments through Bancard's embedded iframe ("Compra Simple"). Each site loads **its own** commerce keys from the
panel; Ruweb holds no shared Bancard account.

| Piece | Where |
|---|---|
| HTTP client | `app/Shop/Payments/Bancard/BancardClient.php` (single_buy, get_confirmation, rollback; 20 s timeout, 10 s connect; no retries) |
| Tokens | `app/Shop/Payments/Bancard/BancardToken.php` (md5 formulas, constant-time comparison) |
| Keys | `app/Shop/Payments/Bancard/BancardCredentials.php` (one environment's pair; refuses to be serialized or dumped) |
| Answers and errors | `app/Shop/Payments/Bancard/BancardResponse.php`, `app/Shop/Payments/Bancard/BancardException.php` |
| Provider | `app/Shop/Payments/Bancard/BancardProvider.php` |
| Webhook | `app/Http/Controllers/Shop/BancardWebhookController.php`, `POST /webhooks/bancard` (`webhooks.bancard`) |
| Sweep | `app/Console/Commands/ShopExpirePaymentsCommand.php` (`shop:expire-payments`) |
| Stray charges | `app/Services/Shop/StrayChargeOperations.php` |
| Connector card | `app/Connectors/Bancard/BancardConnector.php` ([../operations/connectors.md](../operations/connectors.md#bancard-card)) |
| Config | `config/cms.php` → `shop.bancard`: `urls.staging`, `urls.production`, `staging_url_override` (`BANCARD_STAGING_URL`), `checkout_js_version` (`5.0.1`) |

### Verified facts (2026-11-23)

Sources read in full on 2026-11-23: Bancard's manual "Integración con eCommerce Bancard — Compra Simple" version
0.3.1 (23-page PDF published by the AFD:
https://www.afd.gov.py/userfiles/files/transparencia/ecommerce-bancard-compra-simple-version-0-3-1.pdf), Bancard's
**official** Python SDK and JS library (https://github.com/Bancard/bancard-connectors, last push 2025-04-02;
https://github.com/Bancard/bancard-checkout-js), Bancard's Operating Rules
(https://www.bancard.com.py/storage/app/media/RO-CUS-actualizaci%C3%B3n-20210709.pdf, chapter V) and
https://www.bancard.com.py/vpos. The current vPOS 2.0 manual (versions 1.9–1.15) is **not public**: Bancard hands it
out in the merchant portal (https://comercios.bancard.com.py); only fragments were seen, marked **(snippet)**. What
changed between 0.3.1 and the portal's version is checked when sandbox keys arrive
([Sandbox checklist](#sandbox-checklist-before-certifying), step 1).

| Topic | Fact | Source |
|---|---|---|
| Environments | Production `https://vpos.infonet.com.py`; staging `https://vpos.infonet.com.py:8888` | Manual 0.3.1 §3.1.1; SDK `constants.py` |
| Credentials | Per commerce and environment: **public key** (`[a-zA-Z0-9]{32}`) and **private key** (40 characters), seen and regenerated in the merchant portal. The private key never travels: only inside the md5 token | Manual 0.3.1 §1–§2 |
| Confirmation URL | Set by the merchant in the portal profile ("Modificar información del perfil: nombre, logo y url de confirmación"). Logo ideally 173×55 px, at least 85 px wide | Manual 0.3.1 §1 and §6; https://wiki.adamspay.com/devzone:concepts:service:bancard-vpos |
| Start a payment | `POST {env}/vpos/api/0.3/single_buy` with `{"public_key", "operation": {token, shop_process_id, amount, currency, description, return_url, cancel_url, additional_data?}}` → `{"status": "success", "process_id": "…"}` (20 characters) | Manual 0.3.1 §3.1.1 |
| Formats | `shop_process_id` integer (0.3.1: variable length; 1.x: Entero(15) **(snippet)**); `amount` string with two decimals and a dot (`"150000.00"`); `currency` `PYG`; `description` String(100); `return_url` / `cancel_url` String(255); `cancel_url` optional (falls back to `return_url`) | Manual 0.3.1 §2 and §3.1.1 |
| md5 tokens | See [Token formulas](#token-formulas) | Manual 0.3.1 §2; same in the SDK's `api.py` and v1.9/1.15 **(snippet)** |
| Payment form | Embedded **iframe**: `Bancard.Checkout.createForm('container', process_id, {styles})` from `bancard-checkout-<version>.js`, which mounts `{env}/checkout/new?process_id=…`. When done the iframe `postMessage`s (the library only accepts `event.origin` = Bancard) and redirects to `return_url?status=<message>&description=<detail>` | `src/bancard-checkout.js` of the official repo; manual 0.3.1 §4 |
| Library version | Builds up to **5.0.1**; `/checkout/javascript/dist/bancard-checkout-5.0.1.js` answered 200 in production (Last-Modified 2025-04-09) and on `:8888`; an unknown version answers 404 | https://github.com/Bancard/bancard-checkout-js/tree/master/build + curl |
| Confirmation (webhook) | Bancard `POST`s to the confirmation URL `{"operation": {token, shop_process_id, response ("S"/"N"), response_details, amount, currency, authorization_number, ticket_number, response_code ("00" approved; 05, 12, 15, 51…), response_description, extended_response_description, security_information{…}}}`. **The only way to be certain of a payment.** Answer **200** (`{"status":"success"}`) within **60 s** or Bancard marks it invalid. **No `public_key`**: identified by `shop_process_id`, authenticated by the token | Manual 0.3.1 §3.2.1 |
| Query | `POST {env}/vpos/api/0.3/single_buy/confirmations` with the `get_confirmation` token → `{"status", "confirmation": {…same shape…}, "messages"}` | Manual 0.3.1 §3.3.1 |
| Rollback | `POST {env}/vpos/api/0.3/single_buy/rollback`: reverses a payment, a transaction cancelled in the vPOS page or an **abandoned** one, while it is not **settled** ("cuponada"); after that `TransactionAlreadyConfirmed` and the reversal is manual with Bancard. Errors: `InvalidJsonError`, `UnauthorizedOperationError`, `ApplicationNotFoundError`, `InvalidPublicKeyError`, `InvalidTokenError`, `InvalidOperationError`, `BuyNotFoundError`, `PaymentNotFoundError`, `AlreadyRollbackedError`, `PosCommunicationError`; success `RollbackSuccessful` | Manual 0.3.1 §3.1.2 |
| No answer | Without a confirmation, after **10 minutes** query with `get_confirmation`; if not paid, roll back | Manual 0.3.1 §3.1.2 and §3.2.1 |
| Monitoring | **Every 5 minutes** Bancard POSTs an **empty JSON** to the confirmation URL (uptime) | Manual 0.3.1, "Monitoreo" |
| Response page | Must show date and time, order number, amount, `response_description` and the authorization code when approved. Must **not** show `response_code`, `extended_response_description` or `security_information` | Manual 0.3.1 §6 |
| Other site rules | Contact section; never store card data; JSON with special characters as `\uXXXX` | Manual 0.3.1 §6 |
| Certification | Staging has a **"Lista de test"** that turns green by itself: "Implementación de pagos" (a `single_buy`), "Confirmamos correctamente al comercio" (confirmation answered OK), "Consultamos confirmación correctamente" (`get_confirmation`), "Recibir rollback" (rollback of an **approved** transaction). All green enables "Solicitar certificación": Bancard support buys on the certified URL and enables **Producción** with the production keys | Manual 0.3.1 §3.1.1–§3.3.1; v1.9/1.10/1.15 **(snippet)**; AdamsPay |
| Extra list items | "Ejecución de cliente de prueba: Pago ocasional" and "Ingreso de configuración" with profile photo (AdamsPay); "Recibimos pedido de confirmación del comercio" and "not marked if the JSON carries `test_client`" **(snippet)**; test ID number 9661000 **(snippet)** | idem |
| Staging window | The commerce code has **2 months** in staging to request certification; then it is deactivated | Operating Rules 33.2 |
| Sign-up | The merchant states in writing: portal and certification contacts, developer, **domain to certify**, business model, market, delivery method, and whether it meets 5 policies (security, privacy, returns/cancellations/refunds, delivery with zones and costs, payment methods) and site requirements (product descriptions, prices in local currency, **every cost including shipping**, contact, physical address, "about us"). The merchant bears chargebacks (12 months) and keeps each order's documents 1 year | Operating Rules 34–35 |
| Cost | The commercial page shows "Gs 74.900 IVA incl." without saying monthly or one-off **(not verified)** | https://www.bancard.com.py/vpos |
| Official Python SDK | `process_vpos_webhook` compares the received token with the string **without md5** (bug): not copied | `api.py` around line 359 |

Out of scope in v1: stored cards and token charges (`cards/new`, `charge`), Zimple, pre-authorization,
installments and promotions (`additional_data`), QR.

### Token formulas

All md5, hex, concatenated without separators; `amount` always as sent (`"150000.00"`):

| Operation | Token |
|---|---|
| `single_buy` | `md5(private_key + shop_process_id + amount + currency)` |
| Confirmation (webhook and query answer) | `md5(private_key + shop_process_id + "confirm" + amount + currency)` |
| `get_confirmation` | `md5(private_key + shop_process_id + "get_confirmation")` |
| `rollback` | `md5(private_key + shop_process_id + "rollback" + "0.00")` |

`BancardClientTest` checks them against vectors computed outside PHP; a test that recomputes the token with the same
function would only test the function against itself.

### How Ruweb pays with Bancard

1. **Attempt.** Placing the order creates a pending `payments` row with the method's active environment.
   `shop_process_id` is that row's id (at most 15 digits): a retry is a new row, so a new id.
2. **Start** (`BancardProvider::start()`, called by the order page). Sends `single_buy` with the description
   "Pedido ORD-…" in the order's language and the order page as `return_url`, stores `process_id` and returns the
   iframe; the page loads `bancard-checkout-5.0.1.js` from the attempt's environment and calls
   `Bancard.Checkout.createForm`. It is idempotent: an attempt that already has a `process_id` gets the same iframe
   again without another `single_buy` (the page calls it on every visit; Bancard refuses a second `single_buy` for
   the same `shop_process_id`, and a race between two visits ends with the stored one).
3. **Return.** The customer comes back with `?status=`, which is not trusted: the page queries
   ([checkout.md](checkout.md#the-order-page)).
4. **Notification.** The webhook (below) or a query applies the verified outcome.

### Webhook and monitoring pings

`POST /webhooks/bancard` is registered in the base group only, outside sessions, cookies and CSRF
(`bootstrap/app.php` exempts `webhooks/*`), without redirect rules, behind `throttle:webhooks` (120 per minute per
IP) and **independent of the shop mode**, so a payment in flight is never lost when selling is turned off. `webhooks`
is a reserved first segment for pages and `webhooks/*` is ignored by the 404 monitor.

| Request | Answer | Recorded |
|---|---|---|
| Empty body or `{}` (monitoring ping) | 200 | Nothing |
| Unknown `shop_process_id` | 200 | `payment_events.type = unknown_payment` (token removed) |
| Token that does not verify with the private key of the attempt's environment | **403** | `invalid_token` (token removed); attempt untouched |
| Verified, approved (`response` `S`, `response_code` `00`) | 200 | `notification`; attempt `confirmed`, order `paid`, stock taken, customer email |
| Verified, rejected | 200 | `notification`; attempt `failed`; the order page offers "Try again" |
| Verified, another amount or currency | 200 | `amount_mismatch`; attempt `failed`, store warned, then rolled back ([below](#stray-charges-late-payment-and-another-amount)) |
| Repeated notification | 200 | `notification`; nothing changes |

The state of an attempt never turns into a 5xx: Bancard always gets its 200 unless the token is wrong. Emails are
queued; the only outgoing call inside the request is the rollback of a stray charge, bounded by the client's
timeouts. Staging attempts keep verifying with the staging key after the site switches to production.

Every API call also leaves an `api_call` event (operation, environment, ok, Bancard message key, HTTP status):
never a key, a token or a response body. The panel shows the last event received and the last API call.

### Abandoned attempts: the 10-minute sweep

`shop:expire-payments` (every 5 minutes, any shop mode) takes Bancard attempts still `pending` that reached Bancard
(`process_id`) and have not moved for `cms.shop.checkout.bancard_pending_minutes` (10; measured on `updated_at` too,
so an iframe opened a minute ago is not swept):

1. `get_confirmation`. A verified answer is applied (paid late → confirmed; rejected → failed; another amount →
   failed and reversed). An answer that does not verify, or Bancard unreachable → left for the next run.
2. Nothing paid → `rollback`; once Bancard accepts it the attempt becomes `expired` and the customer can try again
   from the order page. If the rollback fails, the attempt stays pending for the next run.

Then it cancels orders with an automatic method unpaid after `unpaid_order_hours` (48)
([checkout.md](checkout.md#scheduled-clean-up)). The scheduler must run (`schedule:run` every minute from cron);
without it abandoned attempts are never rolled back.

### Panel actions on Bancard payments

Money collected online is never marked paid by hand:

- **Verify payment** (the panel's Confirm on a Bancard attempt) asks Bancard (`get_confirmation`) and applies only a
  verified answer.
- **Fail** or **Cancel order** with an attempt that already reached Bancard first asks it: not paid → rollback, then
  fail or cancel; paid → the payment is applied, the order stays paid and the administrator is offered the refund.
- **Refund payment** sends `rollback`. If Bancard answers `TransactionAlreadyConfirmed` (already settled), nothing
  changes and the screen explains that the reversal is manual with Bancard (Operating Rules: `cac@bancard.com.py`);
  once done, **Mark as refunded by hand** records it. Refunding gives the stock back.

MCP follows the same rules (`orders_transition`, `payments_refund`).

### Stray charges: late payment and another amount

Money Bancard collected that does not belong to a paid order is never confirmed in silence. Two cases:

- **Charged late**: an approval for an attempt that had already `failed` or `expired` (the customer paid after the
  sweep rolled back, or paid twice).
- **Another amount**: an approval whose amount or currency differs from the attempt. A pending attempt fails
  (`amount_mismatch`, the store is warned by mail).

Every caller that applies a verified Bancard answer — the webhook, the order page on return, the sweep, "Verify
payment" in the panel and MCP — goes through `StrayChargeOperations::apply()`, which runs
`PaymentOperations::applyNotification()` and then, for a stray charge, **one automatic rollback** outside any
transaction:

| Outcome | Event (`payment_events.type`) | Email to the orders address |
|---|---|---|
| Bancard reversed it | `late_payment_reversed` | Yes (for another amount on a pending attempt, the mismatch warning already went out and no second mail is sent) |
| Bancard could not (settled, or unreachable) | `late_payment_review` (payload: status, code, `manual_required`, reason `late` / `amount_mismatch`) | Yes: "review it" |

The rollback is tried once: a repeated notification does nothing once reversed, resolved or reported as settled;
after a network failure the next notification tries again. The attempt and the order do not change.

A charge left in review shows in Admin > Orders as **Charge to review** (list and order screen) until an
administrator handles it with Bancard by hand and clicks **Mark charge as resolved** on the order screen: it asks for
confirmation and a note (required, up to 1000 characters), does not call Bancard, and records
`late_payment_resolved` with the note and who did it (`StrayChargeOperations::resolve()`, administrators only). The
latest of the three events decides (`StrayChargeOperations::needsReview()`): only `late_payment_review` is flagged.
An approval recorded over a failed or expired attempt with none of those events (a provider that does not reverse
charges) is flagged too, and can be marked resolved the same way.

### Credentials

- Keys are saved per environment ("Testing" and "Production" tabs) in `payment_methods.secrets` (`encrypted:array`
  with `APP_KEY`). The public key must be 32 letters and digits; the private key cannot be empty. An empty field
  keeps the stored key; **Clear** removes the pair.
- They never leave: not in the HTML (the screen says "configured" and the last 4 characters of the public key), MCP
  (write-only `credentials`), logs, events, exceptions or queued jobs. Snapshots carry them only encrypted.
- **Bound to the site**: saving records the origin (`App\Support\SiteUrl::origin()`) in `bound_origin`. A copy
  restored on another domain, or with another `APP_KEY`, leaves Bancard not ready ("load the keys again"), so a
  staging copy never charges with production keys.
- **Environment**: Testing or Production. Production needs its keys and an explicit confirmation ("charges become
  real"); the screen warns when attempts of the other environment are still pending (they keep verifying with the
  key they were created with). Only the panel switches it.
- **Test connection** sends `get_confirmation` for a `shop_process_id` that no real attempt uses (the largest one):
  `BuyNotFoundError` / `PaymentNotFoundError` mean the keys were accepted, `InvalidPublicKeyError` /
  `InvalidTokenError` mean they were not **(not verified against the real sandbox)**.
- The screen shows this site's **confirmation URL** (`https://<domain>/webhooks/bancard`) with Copy, and warns when
  it is not public HTTPS (`localhost`, `.test`, `http`): Bancard cannot reach it.

## Development fake

Never call Bancard (staging or production) from tests or local QA. Two fakes:

- **PHPUnit**: `tests/Support/FakeBancard.php` (`Tests\TestCase::useFakeBancard()`), an `Http::fake` double that
  validates the md5 token and shape of every request, answers `single_buy`, `confirmations` and `rollback` with
  scenarios (approved, rejected 51, not paid, `TransactionAlreadyConfirmed`, `InvalidTokenError`, timeout), builds
  the webhook Bancard would send with the right token and records the calls.
- **Browser**: a fake vPOS server, `tests/Support/FakeBancardServer/router.php`, that imitates the three API
  endpoints, the iframe (`/checkout/new` with Approve / Reject buttons that POST a correctly signed webhook to the site
  and `postMessage` like the real one) and the checkout script. Run it:

  ```bash
  php -S 127.0.0.1:8899 tests/Support/FakeBancardServer/router.php
  ```

  and in the site's local `.env`:

  ```dotenv
  APP_ENV=local
  BANCARD_STAGING_URL=http://127.0.0.1:8899
  ```

  Then load the fake's keys (the `STAGING_PUBLIC_KEY` / `STAGING_PRIVATE_KEY` constants of `Tests\Support\FakeBancard`,
  or `FAKE_BANCARD_PUBLIC_KEY` / `FAKE_BANCARD_PRIVATE_KEY` if you set them for the server) under Testing in
  Admin > Payments and shipping. The fake posts the webhook to `FAKE_BANCARD_WEBHOOK_URL`, else to
  `<origin of return_url>/webhooks/bancard`. Helpers: `GET /_fake/buys` (state), `POST /_fake/settle?shop_process_id=…`
  (the next rollback answers `TransactionAlreadyConfirmed`), `POST /_fake/reset`.

`BANCARD_STAGING_URL` only replaces staging when `app()->environment('local', 'testing')`: on a real site the
variable is ignored, so it can never redirect payments. Restore `.env` after QA (`APP_URL=http://localhost:8000`).

## Getting a client on Bancard

What the client does, also shown as the step-by-step guide on the Bancard card of Admin > Payments and shipping:

1. Request the vPOS for the business (https://www.bancard.com.py/vpos) and send Bancard the sign-up information
   (domain to certify, business model, delivery, policies; see the table above).
2. Sign in to the merchant portal (https://comercios.bancard.com.py), copy the **Testing** keys, paste them under
   Testing and click **Test connection**.
3. In the portal's application profile, paste the confirmation URL shown on the screen and upload the logo.
4. Make a test purchase on the site, complete the portal's "Lista de test" (for the rollback item: **Refund
   payment** on the approved order in Admin > Orders) and request certification with the site's URL.
5. When Bancard approves, paste the **Production** keys and switch the environment to Production. The first real
   sale is made by the merchant, watching the panel.

The site needs a stable public HTTPS domain (Bancard tests on different days: a temporary tunnel is not enough for
certification) and the pages Bancard checks; the screen lists them as an informative checklist that never blocks:
contact page, physical address, privacy, returns and shipping policies, "about us", shipping costs visible before
paying. Remind the client of the **2-month** staging window.

### Sandbox checklist (before certifying)

Requirement: a commerce with vPOS requested and its **staging** keys; the test site reachable over public HTTPS
(Bancard calls the confirmation URL from outside).

1. **Compare the portal's manual** (current version) with the table above: token formulas, length of
   `shop_process_id` and `description`, new webhook fields, values of `?status=` on return, the `confirmations`
   answer for an unknown buy, whether Zimple must be enabled. Any difference is fixed in code before going on.
2. Load the Testing keys from the panel (never by chat or file), **Test connection**, paste the confirmation URL and
   the logo in the portal profile.
3. Walk the portal's "Lista de test" and note which item each step marked:
   - [ ] "Implementación de pagos": a real purchase on the site (staging iframe) → `single_buy` OK.
   - [ ] "Confirmamos correctamente al comercio": the webhook arrives, the token verifies, we answer 200; the order
     becomes `paid`, stock goes down and the emails go out.
   - [ ] "Consultamos confirmación correctamente": return without webhook, or **Verify payment** in the panel →
     `get_confirmation`.
   - [ ] "Recibir rollback": **Refund payment** of an **approved** purchase from the panel → `RollbackSuccessful`.
   - [ ] Extra items the portal shows ("Ejecución de cliente de prueba", configuration and profile photo, "Recibimos
     pedido de confirmación del comercio") **(snippet: confirm in the portal)**.
   - [ ] Also, even if the list does not ask: rejected card (code ≠ 00) → `failed` and retry with a new
     `shop_process_id`; abandoned → after 10 minutes query and rollback; repeated webhook → idempotent; monitoring
     ping (empty JSON) → 200 without noise; token altered by hand → 403.
4. Check the response page against the table (what is shown and what is not) and the site requirements.
5. "Solicitar certificación" with the site's URL (and user/password if the test site asks). After approval, load
   the Production keys and switch the environment from the panel.

## Known limits

- **Not verified against the real sandbox yet.** Everything Bancard-specific is tested against the fakes above,
  built from manual 0.3.1 and the official SDK and library. Pending until staging keys arrive: the current manual's
  differences, the `?status=` values, the HTTP codes of errors, and how `confirmations` answers for an unknown buy
  (which "Test connection" relies on).
- **`single_buy` timeout after Bancard created the buy.** If `single_buy` times out but Bancard did create it, the
  attempt has no `process_id` and the next `single_buy` with the same `shop_process_id` is refused. On that refusal
  the order page queries Bancard (`get_confirmation`) for that `shop_process_id`: paid → the payment is applied;
  not paid → the attempt expires and the customer sees "Retry", which opens a new attempt with a new
  `shop_process_id`. The orphan buy is not rolled back (Ruweb never received its `process_id`), but its form was
  never shown, so nobody can pay it. **Unverified:** the exact error key real Bancard returns for a repeated
  `shop_process_id`; the code does not depend on it (the development fake uses its own key). Check it in sandbox.
- **Settled transactions** (`TransactionAlreadyConfirmed`) cannot be rolled back from Ruweb: refunds and stray
  charges then need the merchant to act with Bancard, and the panel records it by hand.
- **No stock reservation**: two customers can pay for the last unit; the second order is paid with a shortfall
  ([checkout.md](checkout.md#stock)).
- **The scheduler is required**: without `schedule:run`, abandoned attempts are never queried or rolled back and
  unpaid orders are never cancelled.
- One currency per site (`PYG` for Bancard); out of scope: stored cards, Zimple, installments, QR, pre-authorization.

## Tests

`BancardClientTest` (formulas with fixed vectors, request bodies, URLs per environment, override only in
`local`/`testing`, errors, timeouts, no key in logs or exceptions), `BancardPaymentTest` (start and idempotency,
webhook cases, `?status=` not trusted, sweep, stray charges and their rollback, page contents), `AdminShopPaymentsTest`
(keys encrypted and never in the HTML, environment, test connection, site binding, zones), `AdminOrdersTest` (verify,
refund, manual refund, charges to review), `PaymentOperationsTest` and `McpOrdersTest` (no key or token in any
response).
