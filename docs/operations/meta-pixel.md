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

1. **Pixel (dataset) ID** (required to activate): digits only. Administrador de eventos › «Conjuntos de datos»: the
   «Identificador» under the pixel name (it is also in the URL, `/list/dataset/<id>/overview`). When the site already
   had a pixel ID in Admin > Analytics, that ID is the default.
2. **Conversions API token** (optional): Administrador de eventos › «Conjuntos de datos» › the pixel › «Configuración» ›
   «API de conversiones» › «Configurar la integración directa» › «Configurar sin Dataset Quality API» › «Generar
   identificador de acceso» (Meta's Spanish UI calls the token «identificador de acceso»). Without it only the browser
   measures. **Do not** also press «API de conversiones con Meta» › «Conectar ahora» on that screen: it is Meta's own
   server integration for the whole business portfolio, and with Ruweb's token both would send the same events.
3. **Test events code** (optional, only while testing): the pixel › «Probar eventos» › choose «Sitio web» in «Selecciona
   un canal» › «Confirma que los eventos de tu servidor…» shows `test_event_code: TEST12345`.
4. Activate. The card shows the status; the connector's only requirement is a valid pixel ID.

The Spanish labels were checked against Events Manager in its Spanish (Spain) interface on 2026-11-24, during the real
test with a test dataset. The English labels in `lang/en/meta_pixel.php` ("Datasets", "ID", "Set up direct
integration", "Set up without Dataset Quality API", "Generate access token", "Select a channel") are translations of
those and **have not been verified** against Meta's English interface.

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
`<noscript>` image of `PageView` carries it too (`eid`). The same page says:

- the browser and server copies must arrive within **48 hours** of the first one with that `event_id`; when they do
  not differ meaningfully, Meta generally keeps **the one received first** (so a server copy that arrives first is
  kept and shows «Procesados», and the later browser copy is the one dropped);
- the alternative method (`event_name` + `fbp` and/or `external_id`) only works when the browser event arrives first.
  Ruweb relies on `event_id` + `event_name`; `fbp` goes in every server event anyway, and no `external_id` is sent.

How to check it is working: [Verifying deduplication](#verifying-deduplication).

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
    script. Those cookies are written by Meta's JavaScript without Laravel's encryption, so they are excluded from
    `EncryptCookies`.
  - `fbc` (`MetaPixelUserData::browserIds()`; Meta, "ClickID and the fbp and fbc Parameters", updated 2026-01-09,
    consulted 2026-11-24:
    <https://developers.facebook.com/documentation/ads-commerce/conversions-api/parameters/fbp-and-fbc>): a valid
    `_fbc` cookie is sent **as it is** (Meta's script picks the subdomain index, e.g. `fb.2.` on `www.example.com`).
    Without the cookie, or when the URL brings a different `fbclid` (a new ad click; the cookie's click ID is what
    follows its last `.`), the server builds `fb.1.{ms}.{fbclid}`: Meta asks for subdomain index `1` when the value
    is generated on the server, the time when the `fbclid` was first seen, and the click ID unchanged (case
    sensitive). The result is worked out **once per request** and kept in the request attributes, so the `PageView`
    and `ViewContent` of one page carry the same `fbc`. On the first page of an ad click the browser cookie does not
    exist yet (Meta's script writes it after the page loads), so that page's server events carry the `fb.1.` value
    and the browser's its own (`fb.2.` on a subdomain): the click ID is the same; from the next page on, both send
    the cookie.
  - for `Purchase`, from the order: `em`, `ph`, `fn`, `ln`, `ct`, `country`; for `Lead`, from the contact form: `em`,
    `ph` and, when a name was typed, `fn`/`ln` (`MetaPixelUserData::fromContact()`). Normalized as Meta asks and
    hashed with SHA-256 before the event is queued (Meta, "Customer Information Parameters", updated 2026-01-09,
    consulted 2026-11-24); they go only in the server event, never in the page. The browser ids of the customer are
    kept when the order is placed (cache, 30 days) because the payment is usually confirmed from another request
    (the Bancard webhook).

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

What reaches Meta about a person, besides the cookies of its own script:

- **Purchase**: email, phone, first and last name and city of the order, and the country of the site.
- **Lead** (contact form): the email, phone and name the visitor typed. Hermes' decision (2026-11-24, Rubén may
  object): it improves matching of leads with Meta accounts, which is what lets a business optimise and measure ads
  for contacts. The subject and the message are **never** sent.
- Always hashed (SHA-256, after Meta's normalisation) on the server before the event is queued: the job payload, the
  logs and the page never hold them in clear, and the browser event carries none of them.

The site owner's privacy policy has to say that the Meta Pixel is used and that the contact details of orders and of
the contact form are shared with Meta in that form; the panel's Privacy note says the same in plain words.

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

1. In the Administrador de eventos open the pixel › «Probar eventos», choose «Sitio web» in «Selecciona un canal»
   and open «Confirma que los eventos de tu servidor…»: copy the `test_event_code` it shows.
2. Paste it in "Test events code" on the connector detail, save, and press **Send test event**. The site sends a
   `PageView` through the Conversions API now, with that code (`MetaPixelOperations::sendTestEvent()`, throttled to 6
   per minute), and shows what Meta answered: received, Meta's error message, or "could not be reached".
3. The event appears in «Probar eventos» within seconds. While the code is saved, every server event of the site
   carries it and shows up there too: browse the site to see `ViewContent`, `AddToCart`… arrive. That screen is for
   checking that server events arrive and what they carry; it is **not** the place to judge deduplication (see
   [Verifying deduplication](#verifying-deduplication)).
4. **Delete the code when done.** Meta: "The `test_event_code` field should be used only for testing. You need to
   remove it when sending your production payload." The same page also says: "Events sent with `test_event_code` are
   not dropped. They flow into Events Manager and are used for targeting and ads measurement purposes." (Meta, "Using
   the API", updated 2026-07-17, consulted 2026-11-24). So the panel does **not** claim that test events are left out
   of the ads; it asks to delete the code because Meta reserves it for testing.

## Verifying deduplication

«Probar eventos» shows the server events because they carry the test code; browser events only show there when the
site is opened from that screen («Confirma que los eventos del sitio web…», which tags that browser session). In the
real test (2026-11-24), `ViewContent`, `AddToCart`, `InitiateCheckout`, `Purchase` and `Lead` from the server showed
«Procesados», not «Deduplicados», although the browser sent the same `event_name` and `event_id` (checked in the
network log against the job payloads): the browser copies were not on that screen, and Meta keeps the copy that
arrives first. Some `PageView` rows did show «Deduplicados» («se ha omitido porque es el duplicado de un evento
idéntico que ya se ha enviado»).

To verify it for real, without the test code:

1. Delete the test events code on the connector detail and save.
2. Browse the site as a visitor (a browser where nobody is signed in to the panel, no ad blocker): a product, add to
   cart, checkout, the contact form.
3. Wait about 30 minutes («Información general» says events may take up to 30 minutes to appear).
4. Administrador de eventos › the pixel › «Información general»: each event (e.g. «Ver contenido») should list both
   connection methods (browser and server). Open the event's details and its event deduplication tab: it shows the
   rate of events deduplicated per source and how many events of each source carry `event_id` (Meta, "Verifying Your
   Setup", consulted 2026-11-24:
   <https://developers.facebook.com/documentation/ads-commerce/conversions-api/verifying-setup>). The Spanish name of
   that tab has not been seen in a real test yet.
5. A high deduplication rate and `event_id` on close to 100% of both sources means it works. A warning there means
   the ids do not match: look for another copy of the pixel in Custom code or the theme, or for Meta's own «API de
   conversiones con Meta» integration turned on too.

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
| `PageView` shows as «Evento personalizado» in «Probar eventos» | Only Meta's label. Both sides send the name `PageView` (`fbq('track', 'PageView')` / `ev=PageView` in the browser request, `event_name: "PageView"` on the server), as Meta's base code does. Meta's standard-event table (Meta Pixel reference, consulted 2026-11-24) does not list `PageView`, the base code's default event, and that screen labels it "custom". Nothing to fix; do not rename it, or browser and server would stop deduplicating. |
| Server events «Procesados», not «Deduplicados», in «Probar eventos» | Expected while testing: see [Verifying deduplication](#verifying-deduplication). Check it in «Información general» without the test code. |
| Browser `fbc` (`fb.2.…`) differs from the server one (`fb.1.…`) on the first page of an ad click | Expected: the cookie does not exist yet on that request, so the server builds it (index `1`, as Meta asks). Same click ID; later pages send the cookie as it is. |
| Events counted twice from the server | Meta's «API de conversiones con Meta» › «Conectar ahora» was turned on as well as Ruweb's token: disconnect it in the pixel's «Configuración» (or in the portfolio settings). |
| Nothing from the browser in an automated test browser | Meta's script sends nothing from headless Chromium (`HeadlessChrome` user agent, `navigator.webdriver`). Test with a normal browser. |

## Tests

`tests/Feature/MetaPixelPanelTest.php` (registry, panel, token storage, origin binding, test event),
`tests/Feature/MetaPixelEventsTest.php` (every event, dedup ids, feed ids, hashing incl. the Lead's contact data, `fbc`
once per request, exclusions, consent hook, Analytics snippet) and the `meta_pixel` case of `tests/Feature/McpConnectorsTest.php` (the token never appears in MCP or REST).
