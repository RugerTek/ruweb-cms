# Meta Pixel and Conversions API

The `meta_pixel` connector ("Píxel de Meta") tells Meta what visitors do on the site so the client's Facebook and
Instagram ads reach the right people and their results can be measured. It is a connector of its own, in the
**Advertising and measurement** group of Admin > Connectors (`ConnectorCategory::Marketing`), separate from the
catalog feed ([connectors.md](connectors.md)): it publishes no file and works **with the shop off** (a site without a
shop still measures page views and contact form leads).

| Piece | Where |
|---|---|
| Connector definition (settings, requirement, event list, official links) | `app/Connectors/MetaPixel/MetaPixelConnector.php` |
| Runtime: what is measured, consent hook, dedup ids, server event builder | `app/Services/Marketing/MetaPixel.php` |
| `content_ids`, `value`, `currency` of products, carts and orders | `app/Services/Marketing/MetaPixelContent.php` |
| Hashed customer data (`user_data`) | `app/Services/Marketing/MetaPixelUserData.php` |
| Conversions API client (Laravel HTTP, no SDK) | `app/Services/Marketing/MetaConversionsApi.php` |
| Queued server event | `app/Jobs/SendMetaPixelEvent.php` |
| Token, test event (panel operations) | `app/Services/Marketing/MetaPixelOperations.php` |
| Order listeners (Purchase) | `app/Listeners/Marketing/MetaPixelOrderEvents.php` |
| View composers, `_fbp`/`_fbc` cookie exception | `app/Providers/MetaPixelServiceProvider.php` |
| Browser snippet (`fbq`) | `resources/views/partials/meta-pixel.blade.php`, included in `layouts.public` |
| Panel detail | `resources/views/admin/connectors/meta-pixel.blade.php` |
| Texts | `lang/es/meta_pixel.php`, `lang/en/meta_pixel.php` |
| Configuration | `config/cms.php`: `connectors.meta_pixel` and the `meta_pixel` block |

## Setup (what the client does)

On `/admin/connectors/meta_pixel`:

1. **Pixel (dataset) ID** (required to activate): digits only, from Events Manager › Data sources. When the site
   already had a pixel ID in Admin > Analytics, that ID is the default.
2. **Conversions API token** (optional): Events Manager › the pixel › Settings › Conversions API › "Generate access
   token". Without it only the browser measures.
3. **Test events code** (optional): from Events Manager › Test events (for example `TEST12345`), only while testing.
4. Activate. The card shows the status; the connector's only requirement is a valid pixel ID.

## What is measured, and where

Every event goes to the browser (`fbq('track', …)`) and, when a token is saved, also to the Conversions API, with
the **same event name and `event_id`** on both sides.

| Event | Starts in | Browser side |
|---|---|---|
| `PageView` | Every public page using `layouts.public` (the partial) | Same page |
| `ViewContent` | Product page, view composer on `shop.show` (the variant of `?variante=` when it belongs to the product) | Same page |
| `AddToCart` | `Shop\CartController::add()` once the item is in the cart | Flashed to the page the POST redirects to (the cart), once |
| `InitiateCheckout` | Checkout page, view composer on `shop.checkout` (only with a non-empty cart) | Same page |
| `Purchase` | Server: `OrderPaid` (manual confirmation or the Bancard webhook). Browser: the order page once the order is paid | Once per order; `event_id` = `purchase-{order number}` on both sides |
| `Lead` | `Public\ContactFormController::store()` after a real submission (not on a honeypot hit) | Flashed to the page it redirects to |

`custom_data`:

- `content_ids` are **the same `g:id` the catalog feed sends** (the variant id when there is one, `g:item_group_id`
  for a product page without a chosen variant, with `content_type` `product_group`), so ads built on the catalog
  match the events. Otherwise `content_type` is `product`.
- `contents` (`id`, `quantity`), `value` and `currency` (`cms.shop.currency`, `PYG`); `num_items` on
  `InitiateCheckout` and `Purchase`; `order_id` (the order number) on `Purchase`.

Nothing is measured, in the browser or the server:

- while the connector is off or has no valid pixel ID;
- inside the panel's previews (`$isEditorPreview`);
- for a signed-in panel user (their visits would muddy the numbers); an order placed by one sends no `Purchase`;
- when the consent hook says no ([Privacy](#privacy-and-consent)).

## Deduplication

Meta keeps one event when a browser and a server event of the same pixel share `event_name` and `event_id` (Meta,
"Handling Duplicate Pixel and Conversions API Events", updated 2026-06-28, consulted 2026-11-24:
<https://developers.facebook.com/documentation/ads-commerce/conversions-api/deduplicate-pixel-and-server-events>).
`MetaPixel::track()` generates the id (a UUID; `purchase-{number}` for purchases) once and uses it for both. The
`<noscript>` image of `PageView` carries it too (`eid`).

## Conversions API

- Endpoint: `POST {graph_url}/{graph_api_version}/{pixel_id}/events` (Meta, "Using the API", updated 2026-07-17,
  consulted 2026-11-24: <https://developers.facebook.com/documentation/ads-commerce/conversions-api/using-the-api>).
  The token goes in the JSON body, never in the URL, so it cannot reach an access log.
- **Graph API version: `v27.0`**, fixed in `config/cms.php` (`cms.meta_pixel.graph_api_version`). It was the latest
  one on 2026-11-24 (Meta Graph API changelog, released 2026-11-04); Meta supports each version for the Conversions
  API for at least two years. Moving to a newer one is a config change plus a run of `MetaPixelEventsTest`.
- Other settings in the same block: `graph_url`, `timeout` (seconds), `country` (`py`, ISO 3166-1 alpha-2 of the
  customers) and `phone_country_code` (`595`, added to phone numbers written without one).
- Each event is queued (`SendMetaPixelEvent`) after the database transaction commits; on the `sync` queue it runs
  after the response, so a visitor never waits for Meta. The job payload holds only the event, already hashed: the
  pixel ID, token and test code are read when it runs (the token never sits in the queue, and a connector turned off
  meanwhile sends nothing).
- Connection errors, 5xx and 429 are retried (3 tries, 30 s and 300 s); any other answer is logged as
  `Meta Conversions API did not accept an event.` with the event name, HTTP status, Meta's message and `fbtrace_id`.
  Nothing personal and no token is logged; nothing is ever thrown to a page or to the checkout.
- Every event has `action_source` `website`, `event_source_url` (the page URL without query string; on the order
  page, whose URL carries the customer's private token, the checkout URL instead) and `user_data`:
  - always: `client_ip_address`, `client_user_agent`, and `fbp`/`fbc` from the `_fbp`/`_fbc` cookies of Meta's
    script (`fbc` is built from `fbclid` when the cookie does not exist yet). Those cookies are written by Meta's
    JavaScript without Laravel's encryption, so they are excluded from `EncryptCookies`.
  - only for `Purchase`, from the order: `em`, `ph`, `fn`, `ln`, `ct`, `country`, normalized as Meta asks and hashed
    with SHA-256 before the event is queued (Meta, "Customer Information Parameters", updated 2026-01-09, consulted
    2026-11-24). The browser ids of the customer are kept when the order is placed (cache, 30 days) because the
    payment is usually confirmed from another request (the Bancard webhook).

## The token: encrypted and bound to the domain

- Saved only from the panel (`MetaPixelOperations::saveAccessToken()`), in `connectors.secrets` (`encrypted:array`
  cast, encrypted with `APP_KEY`, like the Bancard keys of `payment_methods`), together with the site origin in
  `connectors.bound_origin` (migration `2026_11_24_000003_add_secrets_to_connectors_table`).
- A copy of the database restored under another domain or another `APP_KEY` cannot use it: the panel says "paste it
  again" and server events stop until it is re-entered. Nothing breaks.
- The panel shows only "ends in xxxx"; the field is empty, and leaving it empty keeps the saved token. A checkbox
  deletes it.
- MCP never returns it: `connectors_get`/`connectors_list` report `conversions_api.token_configured` and
  `token_needs_reentry`, and an `access_token` sent in `connectors_update` settings is ignored with an
  `unknown_setting` warning ([mcp.md](mcp.md#connectors)). The model hides `secrets` from `toArray()`.

## Privacy and consent

The base has **no cookie banner**. While the connector is active, the pixel loads for every measured visit, and the
site owner is responsible for the privacy notice (and, where the law asks for it, consent) of their site.

A banner added later blocks the pixel through one hook, without touching the connector:

```php
// In a service provider's boot():
\App\Services\Marketing\MetaPixel::resolveConsentUsing(
    fn (\Illuminate\Http\Request $request) => $request->cookie('cookie_consent') === 'accepted'
);
```

With `false`, neither the browser snippet nor the server events run for that visit (and an order placed without
consent sends no `Purchase`). A hook that throws counts as no consent. `null` restores the default.

## Relation with the Admin > Analytics pixel

Admin > Analytics already had a "Meta Pixel ID" field (Setting `meta_pixel_id`) that prints Meta's base code
(`PageView` only) in `components/seo-meta` and its `<noscript>` in `layouts.public`. While the connector is active
(`MetaPixel::active()`), that snippet is not rendered, so `PageView` is not counted twice; with the connector off, it
works as before. The connector's pixel ID defaults to that setting.

## Test event and Events Manager › Test events

1. In Events Manager open the pixel › **Test events** and copy the code it shows.
2. Paste it in "Test events code" on the connector detail, save, and press **Send test event**. The site sends a
   `PageView` through the Conversions API now, with that code (`MetaPixelOperations::sendTestEvent()`, throttled to 6
   per minute), and shows what Meta answered: received, Meta's error message, or "could not be reached".
3. The event appears in **Test events** within seconds. While the code is saved, every server event shows up there
   too: browse the site to see `ViewContent`, `AddToCart`… arrive, marked as deduplicated when the browser sent the
   same one.
4. **Delete the code when done**: events with a test code are only for that screen.

The test needs the pixel ID, a token and the code; the panel says which one is missing.

## Troubleshooting

| Symptom | Check |
|---|---|
| No `fbq` in the page source | Connector active with a valid pixel ID? Signed in to the panel in that browser (not measured)? A consent hook returning `false`? |
| Browser events but no server events | Token saved and usable ("ends in …" on the detail, `token_configured` in MCP)? A queue worker running when `QUEUE_CONNECTION` is not `sync`? Laravel log for `Meta Conversions API did not accept an event.` |
| "Paste the token again" | The database was copied from another domain or `APP_KEY`, or `APP_URL` changed: enter the token again. |
| Test event: invalid token / permission error | Generate a new token from the same pixel's Conversions API settings; tokens of another pixel or business are refused. |
| Events counted twice in Events Manager | The Admin > Analytics snippet is suppressed while the connector is active: look for another copy of the pixel in Custom code or the theme. |
| `Purchase` missing | It is sent when the order is marked paid (`OrderPaid`), not when it is placed; orders placed by a panel user are not measured. |
| `content_ids` do not match catalog items | They are the feed's `g:id`; compare with the feed preview in the Meta connector detail. |

## Tests

`tests/Feature/MetaPixelPanelTest.php` (registry, panel, token storage, origin binding, test event),
`tests/Feature/MetaPixelEventsTest.php` (every event, dedup ids, feed ids, hashing, exclusions, consent hook, Analytics
snippet) and the `meta_pixel` case of `tests/Feature/McpConnectorsTest.php` (the token never appears in MCP or REST).
