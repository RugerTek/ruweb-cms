# Connectors

> Admin > Connectors: how a site hands its catalog to another platform, how to add a connector, and the Meta
> (Facebook and Instagram) catalog feed with what the server and the client must do for Meta to read it.

A connector links the site to an external platform without code: the administrator opens **Connectors** in the
panel, turns a connector on and follows the guide on its screen. The first one is **Meta**: a product feed that
Commerce Manager downloads on a schedule, so the shop's products appear in the catalog used by Facebook and
Instagram shops and catalog ads. Two modes: the **manual feed** (v1: the client pastes the URL in Commerce Manager)
and **Connect with Facebook** (v2: Ruweb creates the feed in the client's catalog through the Graph API and pushes
changes at once; [Connect with Facebook (v2)](#connect-with-facebook-v2)). The manual feed stays available as the
fallback.

## What the administrator sees

Rule: **Connectors is the showcase and the status of everything external (sales channels and payment methods); the
settings of each one live in a single place** (Meta, the Meta Pixel and the Amiba chatbot on their detail, Bancard in
Admin > Payments and shipping), never duplicated on the card.

- **Connectors is always in the sidebar for administrators**, whatever modules are on: it is how a client finds
  out what the site can connect to. Editors do not see it and its routes answer 403 (`role:admin` group of
  `routes/admin.php`, routes in `routes/connectors-admin.php`). It is an operational screen, valid in both admin
  profiles ([../concepts/admin-profiles.md](../concepts/admin-profiles.md)).
- `/admin/connectors` is a grid of cards, one per registered connector, grouped under a heading per
  `Connector::category()` (`App\Connectors\ConnectorCategory`, in the order of its cases: "Sales channels" for
  Meta, "Payment methods" for Bancard, "Advertising and measurement" for the Meta Pixel, "Customer service" for the
  Amiba chatbot; empty groups are not shown), each with its status
  (`App\Connectors\ConnectorStatus`):

  | Status | When | The card offers |
  |---|---|---|
  | `available` | Requirements met, connector not active | Published product count (and the feed item count when variants make it differ), how many would enter the feed, and Activate |
  | `needs_action` | A requirement is unmet (for Meta: the product catalog is off), even if the connector is active; the chip reads "Paused" for an active connector | What is missing and, when the administrator can fix it, the button that does (for Meta: turn on the product catalog, through `ShopModuleOperations::setMode()`, see [../shop/catalog.md](../shop/catalog.md)) |
  | `connected` | Active and every requirement met | Included and skipped counts of the last generated file, last download by the platform (for Meta, "never downloaded" until Meta fetches it) |
  | `needs_reconnect` | A remote connection (`App\Contracts\Connectors\RemoteConnector`; today Meta v2) was rejected by the platform: Meta revoked the token or the client removed the catalog | What happened and a direct "Connect again" button ([Revocation](#revocation-and-needs_reconnect)) |

- `/admin/connectors/{key}` is the detail: status and last downloads, the feed URL with Copy and Regenerate, the
  setup guide, the default values form, a preview of the first items with the full list of skipped items (with
  reason and an "Edit product" link) and notices (only for items that enter the feed: a skipped item is listed once),
  and Deactivate. Connectors without a feed have no detail of their own: Bancard's sends the administrator to its
  settings ([Bancard card](#bancard-card)).

All texts live in `lang/es/connectors.php` / `lang/en/connectors.php` (parity rule:
[../admin/translations.md](../admin/translations.md)).

## Architecture

| Piece | Where | Owns |
|---|---|---|
| Registry entries | `config/cms.php`: `connectors` (key => class) and `catalog_sources` (key => class) | Which connectors and sources exist. Today `meta`, `bancard` and `meta_pixel`, and the `products` source. |
| `Connector` | `app/Contracts/Connectors/Connector.php` | `key()`, `label()` and `description()` (translation keys), `icon()` (Blade view), `category()` (`ConnectorCategory`: grid group), `requirements()`, `defaults()`, `rules()` |
| `FeedConnector` | `app/Contracts/Connectors/FeedConnector.php` | Extends `Connector` for platforms that download a file: `source()`, `check()`, `write()`, `contentType()`, `extension()`, `fetchAgents()` |
| `CatalogSource` | `app/Contracts/Catalog/CatalogSource.php` | Where a feed reads items from: `key()`, `available()`, `items()` (a cursor-backed `LazyCollection`, never the whole catalog in memory), `count()` (items) and `groupCount()` (products: the variants of one product count once) |
| `CatalogItem` | `app/Connectors/CatalogItem.php` | Immutable item handed from source to connector: id, group id, title, plain-text description, relative URL, media ids, `Money` price and sale price, availability, brand, variant attributes, panel edit URL, category path (top level first) |
| `ConnectorRequirement`, `ConnectorAction` | `app/Connectors/` | What a connector needs from the site and the panel button that resolves it (`ConnectorRequirement::shopCatalog()`) |
| `FeedWarning`, `FeedResult` | `app/Connectors/` | Why an item is skipped or only flagged; the totals, sample and warnings of a run |
| `ConnectorRegistry` | `app/Connectors/ConnectorRegistry.php` | Read side: resolves classes on every call, status, effective settings (defaults + stored), and `feedRecord()` that decides whether a public feed request gets a document or a 404 |
| `Connector` model | `app/Models/Connector.php`, table `connectors` | One row per connector, created on first activation: `enabled`, `settings_json`, token hash and encrypted token, fetch log and counters, and `secrets` (encrypted credentials for an external API, today the Meta Pixel's Conversions API token) with the `bound_origin` they were saved under |
| `ConnectorOperations` | `app/Services/Connectors/ConnectorOperations.php` | The only write path, shared by the panel and MCP |
| `ConnectorServiceProvider` | `app/Providers/ConnectorServiceProvider.php` | Gate `manage-connectors` (administrators), the `feeds` rate limiter, and the model listeners that mark feeds stale |

`ConnectorOperations` (every actor method requires an administrator and runs in one transaction):

```php
public function activate(User $actor, string $key): Connector;      // ValidationException while a requirement is unmet
public function deactivate(User $actor, string $key): Connector;    // feed → 404; settings and token kept
public function updateSettings(User $actor, string $key, array $settings, ?string $expectedRevision): Connector; // partial; stale revision → 409
public function rotateToken(User $actor, string $key): string;      // plain token, for the panel only
public function enableCatalog(User $actor): void;                   // ShopModuleOperations::setMode($actor, 'catalog') unless the catalog is on
public function preview(string $key, int $limit = 20): FeedResult;  // walks the source through check(), writes nothing
public function recordFetch(Connector $connector, ?string $userAgent): void;
public function recordCounts(Connector $connector, FeedResult $result): void;
public function revision(Connector $connector): string;             // expected_revision for clients
public function touchFeeds(): void;                                 // marks every generated feed stale
public function feedsTouchedAt(): ?float;                           // microtime of the last touchFeeds()
public function feedIsStale(int $generatedAt): bool;                // older than FEED_TTL_SECONDS or not newer than touchFeeds()
```

Never write a `connectors` row any other way: the panel, MCP and tests all go through it.

## Adding a connector

1. **A class** implementing `App\Contracts\Connectors\Connector`, or `FeedConnector` when the platform downloads a
   file. `key()` returns its config key (lowercase letters, digits, `_`); `label()` and `description()` return
   translation keys; `icon()` returns a view under `resources/views/admin/connectors/icons/`
   (`resources/views/admin/connectors/icons/generic.blade.php` is the fallback shape to copy); `category()` returns
   the `ConnectorCategory` it is listed under (`SalesChannel` for a feed connector, `Payments` for a payment method).
2. **Requirements** are evaluated on every call, never cached. Reuse `ConnectorRequirement::shopCatalog()` when
   the connector needs products; an unmet requirement sets `needs_action`, blocks `activate()` and turns the feed
   off without touching the stored row.
3. **Settings**: every key the connector reads goes in `defaults()`; `rules()` validates them and keys without a
   rule are dropped on save. Defaults are what the feed uses until the administrator saves the form.
4. **A feed connector** names its source (`source()`, a `cms.catalog_sources` key), decides per item in `check()`
   (a `FeedWarning` with `skips` leaves the item out; the others are notices shown in the panel), and writes the
   whole document to a stream in `write()`, which receives only items that passed and turns relative URLs
   absolute with `App\Support\SiteUrl::origin()`. `fetchAgents()` lists lowercase user-agent fragments of the
   platform's downloader, used to record when the platform itself last fetched the feed.
5. **Register it** in `cms.connectors` (`'google_merchant' => …::class`). No migration: every connector shares the
   `connectors` table, and the public feed route (`GET /feeds/{connector}/{token}.xml`, `routes/connectors.php`)
   and the panel routes already take any registered key.
6. **Translations** in both `lang/es/connectors.php` and `lang/en/connectors.php`: `<key>_label`,
   `<key>_description` and whatever the requirements and guide use.
7. **Tests.** `Tests\TestCase::useFakeConnectors()` swaps the registry entries for
   `tests/Support/FakeFeedConnector.php` and `tests/Support/FakeCatalogSource.php`, so panel and MCP behaviour can
   be tested without a real platform; the connector's own test covers its document and checks.
   `tests/Feature/ConnectorContractsTest.php` covers the registry, statuses and operations.

A new **catalog source** (records other than shop products) is a class implementing
`App\Contracts\Catalog\CatalogSource` plus an entry in `cms.catalog_sources`. It owns which records are public
(published, not expired, base language) and maps them to `CatalogItem`; the connector owns the destination
format. v1 ships only the shop products source (`products`). It sends the path of the product's first category
(lowest id, like the brand) walking `parent_id` up to the top level, at most 10 levels (a parent loop in the data
never hangs the feed).

## Bancard card

Bancard (card payments, [../shop/payments.md](../shop/payments.md#bancard-vpos-20)) appears as a card so a client
browsing Connectors finds it, but it publishes no feed and keeps no settings of its own
(`app/Connectors/Bancard/BancardConnector.php`, key `bancard`, icon
`resources/views/admin/connectors/icons/bancard.blade.php`). Keys, environment and turning it on live in
**Admin > Payments and shipping**.

Requirements, in the order the card explains them (only the first unmet one carries a button):

| Requirement | Unmet when | Button |
|---|---|---|
| Product catalog | The shop is off (`ConnectorRequirement::shopCatalog()`) | Turn on product catalog (when `shop.mode` allows it) |
| `bancard_payments` | The `bancard` payment method is off or not ready (provider registered, keys of the active environment saved, readable and bound to this site) | Configure payments → Admin > Payments and shipping |
| `shop_checkout` | Selling online is off (`shop_mode` is not `checkout`) | Settings > Shop |

Status follows the usual rules: `needs_action` while a requirement is unmet, `connected` while Bancard takes
payments. The `connectors` row is not activated from the card: it mirrors the payment method
(`PaymentMethodOperations::syncConnector()`): on while Bancard is enabled and every requirement is met, off
otherwise. It is resynced on every payment method write (panel and MCP) and when Settings > Shop changes the mode
(`ShopModuleOperations::setMode()`), so the card never says `connected` on a site that does not sell.

`/admin/connectors/bancard` redirects to Admin > Payments and shipping (`#bancard`) while the catalog is on, and back
to the grid otherwise (where the card offers to turn the catalog on). For the same reason the card's name and its
footer link ("See all payment methods") lead straight to Admin > Payments and shipping while the catalog is on, and
the card has no such link otherwise. The card's buttons that only navigate are links (GET), not forms.

## The feed URL

- `GET /feeds/{connector}/{token}.xml`, route `connectors.feed`, registered before the page catch-all and always
  (it survives `route:cache`). `feeds` is a reserved first segment for page slugs and `feeds/*` is in
  `cms.redirects.ignore_paths`, so requests with made-up tokens do not fill the 404 monitor
  ([redirects.md](redirects.md)).
- **404, never 403**, when the connector is unknown, never activated, deactivated, a requirement is unmet (shop
  off) or the token does not match. A 404 confirms nothing to someone guessing.
- **The token is the only secret.** 48 random characters, compared as sha256 in constant time. The encrypted
  copy exists only so the panel can show the URL again; it never leaves through MCP (the `connectors_*` tools
  report status, counters and `token_rotated_at`, and only say where the URL is shown, `feed_url_location`:
  [mcp.md](mcp.md#connectors)). A snapshot restored under another `APP_KEY` keeps serving (validation uses the
  hash) and the panel asks to regenerate the URL.
- **Regenerate** invalidates the old URL at once. In manual mode the client must paste the new one into Commerce
  Manager, or Meta's next scheduled fetch fails; with Connect with Facebook, Ruweb points the Meta feed at the new
  URL by itself and the panel texts (`confirm_rotate_connected`, `token_rotated_connected`) say there is nothing to
  paste.
- **Throttle** `feeds`: 30 requests per minute per IP.
- **No session.** The route drops the session, CSRF and cookie middleware of the `web` group: a download sets no
  cookie and opens no session, so the public answer can be cached.
- **Generated file, not XML per request.** The document lives at `storage/app/private/feeds/{key}.xml` (the
  `local` disk, `App\Services\Connectors\FeedFile`): it is streamed to a temporary file in the same folder and
  renamed over the previous one, so a reader never sees half a document. It is rebuilt on the next request when
  missing, older than `ConnectorOperations::FEED_TTL_SECONDS` (15 minutes) or after `touchFeeds()` (saving or
  deleting a product, variant or media item, or creating, deleting or changing the `enabled`, settings or token of
  a connector). One rebuild runs at a time per connector while other requests keep getting the previous file; if
  a rebuild fails and a previous file exists, the last good document is served and the error is logged.
- **Response.** `Content-Type: application/xml; charset=UTF-8`, `Content-Length`, an `ETag` (xxh128 of the
  file; a matching `If-None-Match` gets 304), `Last-Modified`, `Cache-Control: public, max-age=900` and
  `X-Robots-Tag: noindex, nofollow` (the URL carries a secret). A `Range` header is ignored: the answer is always
  `200` with the whole file, so a platform never stores half a catalog.
- **Images.** JPEG and PNG media keep their own URL; WebP media get a JPEG derivative on the public disk
  (`feed/{media_id}-{fingerprint}.jpg`, `App\Services\Media\FeedImage`), made once while the file or the panel
  preview is built and reused until the image changes; deleting the medium deletes its derivatives.
- **Fetch log.** Every request with a valid token (200 or 304) calls `recordFetch()`: `last_fetched_at` and
  `last_fetch_agent` always, and `last_meta_fetched_at` when the user-agent contains one of the connector's
  `fetchAgents()` (case-insensitive). The detail screen shows both: "Meta last downloaded the catalog" and, in
  small print, the last download by any client with its user-agent. Forging the user-agent requires the secret
  token, so the date is reliable for what it is used for.

## Meta feed format

Sources consulted on **2026-11-23**, the fields page again on **2026-11-24** (dates are the "Updated" line of
each page; the Business Help Center pages carry no date):

| Topic | Source |
|---|---|
| Accepted formats, one field per line in XML | [Catalog reference](https://developers.facebook.com/documentation/ads-commerce/catalog/reference/) (Updated: Apr 22, 2026) |
| Fields and their rules | [Catalog fields](https://developers.facebook.com/documentation/ads-commerce/commerce-platform/catalog/fields) (Updated: Oct 28, 2026) |
| Official RSS sample | [dpa_product_catalog_sample_feed_rss.xml](https://lookaside.facebook.com/developers/resources/?id=dpa_product_catalog_sample_feed_rss.xml) |
| Variants | [Product variants](https://developers.facebook.com/documentation/ads-commerce/catalog/guides/product-variants) |
| Crawler user-agent, gzip | [Meta web crawlers](https://developers.facebook.com/documentation/sharing/webmasters/web-crawlers/) (Updated: May 21, 2026) |
| Access errors, size limits | [Troubleshoot data feed errors](https://business.facebook.com/business/help/2041876302542944) |

Meta's rules change: re-read the fields page when touching the writer and update the date here.

The document is **RSS 2.0 XML** with `xmlns:g="http://base.google.com/ns/1.0"`, starting with the `<?xml`
declaration, a `<channel>` with `title`, `link` and `description`, and one `<item>` per sellable unit with `g:`
fields, **one field per line** (Meta rejects a line longer than 5,242,880 bytes). The fields page says a product
may fail to upload when `id`, `title`, `description`, `availability`, `condition`, `price`, `link` or
`image_link` is missing or malformed, and that at least one of `brand`, `gtin` or `mpn` is needed; the feed
always sends those eight plus `brand`.

| `g:` field | Value | Meta's rule |
|---|---|---|
| `id` | Product or variant SKU; without one, `p{id}` / `v{id}` | ≤ 100 characters, unique in the catalog (duplicates are all ignored), different from every `item_group_id` |
| `item_group_id` | Parent product SKU (`p{id}` without one), only for variants | ≤ 100, the same for every variant of a product |
| `title` | Product title (+ variant name) | ≤ 200 (65 recommended); longer titles are cut with a `title_truncated` notice |
| `description` | Excerpt or description as plain text (tags, entities and links removed); empty → the title, with a `description_from_title` notice | ≤ 9,999, plain text, different from the title |
| `availability` | From stock: tracked without backorder → `in stock` while stock > 0, else `out of stock`; tracked with backorder → `in stock`; untracked → the connector default | `in stock` / `out of stock`. Out-of-stock items are included: Meta shows them as sold out and leaves them out of ads |
| `condition` | Connector default (`new`, `refurbished`, `used`; `new` by default) | Those three values |
| `price` | `Money` as number + space + ISO 4217: `150000 PYG` | Period as decimal point, no symbol |
| `sale_price` | When the compare-at price is higher than the price: `price` = compare-at, `sale_price` = current price | Same format, lower than `price` |
| `link` | Absolute public product URL; variants add `?variante={id}` | `http(s)://` on the business's own domain |
| `image_link` | Featured image as JPEG or PNG (uploads are stored as WebP, which Meta does not accept, and converted to a JPEG whose URL changes with the image) | JPEG or PNG, ≥ 500 × 500 px, ≤ 8 MB; a changed image needs a new URL |
| `additional_image_link` | Gallery (without the featured image) as JPEG or PNG, comma-separated | Up to 50 images, ≤ 2,000 characters in total; the list is cut to fit |
| `brand` | The product's `brand` term, or the connector default (site name) with a `default_brand` notice | ≤ 100 |
| `google_product_category` | Optional connector default | Google taxonomy text or id |
| `product_type` | The product's category path, top level first, joined with ` > ` (`Ropa > Remeras`); left out without a category | ≤ 1,000 |
| `custom_label_0` | Top-level category (`Ropa`); left out without a category | ≤ 100 |
| `custom_label_1` | `oferta` when the item has a `sale_price` (`MetaFeedWriter::SALE_LABEL`); left out otherwise | ≤ 100 |
| `custom_label_2` | The product's own `brand` term; left out without one (the connector default brand goes only to `brand`) | ≤ 100 |
| `color`, `size`, `gender`, `pattern`, `material` | Variant attributes with those keys (case-insensitive), also under the Spanish names `talle`, `talla`, `tamaño` → `size` and `estampado` → `pattern`, and `colour` → `color`. `gender` only when the value is `female`, `male` or `unisex` (sent in lowercase); any other value goes to `additional_variant_attribute` | ≤ 200 |
| `additional_variant_attribute` | Remaining variant attributes as `Key:Value,Key:Value` (no space after the comma; `:` and `,` inside a key or value become spaces), up to 3 pairs | Up to 3 pairs |

Items left out (listed in the panel with their reason and an edit link): `missing_price`, `missing_image`,
`image_not_convertible` (the image could not become a JPEG) and `no_variants` (variants enabled but none exists).
Notices that keep the item: `default_brand`, `image_too_small` (shorter side under 500 px; Meta decides),
`title_truncated`, `description_from_title`.

`product_type` and the custom labels exist so the client can build **product sets** in Commerce Manager (filters
on these fields) and catalog campaigns by category, on sale or by brand; the guide says so in one line. Products get
categories from the `category` taxonomy (`HasTaxonomies::categories()`), assigned in the product form and through
the `products_*` MCP tools ([../shop/catalog.md](../shop/catalog.md)); a product without a category sends neither field.

The feed carries only **published products in the base language**; per-language or per-country feeds are not in v1.

## What the server must allow

Meta downloads the feed with its **FacebookExternalHit** crawler. The strings it publishes are
`facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)` and `facebookexternalhit/1.1`, and
its feed troubleshooting page asks not to block `facebookexternalhit/1.1`. If Meta reports "Your data feed file
couldn't be accessed", "Failed to connect to server" or "Fetching feed from server failed", go through this list.

### Check from outside

Simulate Meta's request against the real URL (Meta's own command, from the crawlers page):

```bash
curl -v --compressed -H "Range: bytes=0-524288" -H "Connection: close" \
  -A "facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)" \
  "https://example.com/feeds/meta/<token>.xml"
```

Expect `200`, `Content-Type: application/xml; charset=UTF-8`, `Content-Encoding: gzip` and a body that starts
with `<?xml`. The command sends `Range`, but the app ignores it: the answer is still `200` with the whole file,
never `206`. A 404 means the app refused it (wrong or regenerated token, connector deactivated, shop off). A 403,
503, a challenge page or HTML in the body means something in front of Laravel answered instead.

The detail screen helps too: if "last download by any client" moves but "Meta last downloaded" does not, the
URL works and Meta's requests are not arriving (blocked upstream, or the URL in Commerce Manager is old).

### gzip

Meta's crawler requirements say the server must use gzip and deflate encodings. The app does not compress: the
web server or proxy does. Nginx's default `gzip_types` covers only `text/html`, so add XML
([deploy-droplet.md](deploy-droplet.md)):

```nginx
gzip on;
gzip_types application/xml text/xml;
```

Apache / cPanel ([deploy-cpanel.md](deploy-cpanel.md)), with `mod_deflate`:

```apache
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE application/xml text/xml
</IfModule>
```

Behind a CDN or proxy, confirm with the `curl` above that the response reaching the client is compressed.
Separately, Meta's help limits scheduled feed files to 4 GB and recommends compressing files over 1 GB; a site
catalog is far below that, so the feed is served as plain `.xml` compressed in transit.

### Firewalls, bot protection and Cloudflare

Anything that challenges or blocks automated clients blocks Meta, which cannot solve a challenge:

- **robots.txt.** `public/robots.txt` disallows only `/admin/` ([../content/seo.md](../content/seo.md)). Do not
  add `/feeds/` or a `facebookexternalhit` disallow.
- **Cloudflare Bot Fight Mode** challenges traffic that looks like a bot and **cannot be skipped** with WAF custom
  rules or Page Rules ([Cloudflare docs](https://developers.cloudflare.com/bots/get-started/bot-fight-mode/),
  consulted 2026-11-23). On a site that publishes a feed, turn it off, or use Super Bot Fight Mode (paid plans),
  which supports Skip rules.
- **Cloudflare WAF custom rules, "Under Attack" mode, JS or managed challenges, rate limiting, country blocks**:
  add a rule that skips them for the feed path, for example expression
  `starts_with(http.request.uri.path, "/feeds/")` with action **Skip**. Exempting the path is safer than
  allow-listing the user-agent, which anyone can send; the token still protects the content.
- **Hosting firewalls** (ModSecurity, Imunify360, fail2ban, "bad bot" lists of cPanel providers) and
  security plugins of a proxy in front: allow `/feeds/` or the `facebookexternalhit` user-agent. Meta's crawler
  page says allow-listing its IP addresses is the more secure option.
- **Basic auth or a maintenance page** on a staging site: Meta cannot read it. Do not put credentials in Commerce
  Manager; test on a public URL.

## Commerce Manager guide (for the client)

The detail screen shows these steps with the client's feed URL and currency (translation keys `guide_*` in
`lang/es/connectors.php`). They follow Meta's help pages
[Upload products with a data feed](https://business.facebook.com/business/help/125074381480892),
[Create a catalog](https://business.facebook.com/business/help/1275400645914358) and
[About scheduled data feed uploads](https://en-gb.facebook.com/business/help/2284463181837648), consulted
2026-11-23, and the labels below were **verified on 2026-11-24** while connecting a test catalog in Commerce Manager
with its interface in Spanish (Spain). The English texts in `lang/en/connectors.php` translate those labels; Meta's
English interface may word them slightly differently.

0. Requirement: a Meta business portfolio (Business Manager) with full control.
1. Open [Commerce Manager](https://business.facebook.com/commerce_manager) → «Añadir productos» (or create a
   catalog): online products type, **do not** tick «Conectarse a una plataforma de socios», choose the portfolio and
   a name → «Siguiente» (this accepts Meta's catalog terms).
2. «Elige cómo añadir tus productos o servicios» → «Sube un archivo de datos» → «Siguiente».
3. «¿Para qué plataforma está formateado tu archivo?» → keep «Commerce Manager».
4. «Elige cómo quieres subir el archivo» → «Utiliza una URL u Hojas de cálculo de Google» → paste the feed URL
   copied from the connector screen. Leave «Información de inicio de sesión» empty → «Siguiente».
5. «Confirmar configuración»: a name, and «Divisa predeterminada», which comes set to **USD**: change it to the
   site currency (**PYG** by default, `cms.shop.currency`; each item carries its currency anyway).
6. «Programación de la lista de datos» → frequency «Cada hora» (the default; keep it: Meta fetches at most once an
   hour, [scheduled feeds](https://developers.facebook.com/documentation/ads-commerce/catalog/guides/scheduled-feeds/))
   → «Subir».
7. Result: «Catálogo» → «Orígenes de datos» shows the item count and «Sin errores»; «Actualizar» makes Meta read the
   feed right away ([Troubleshoot data feed errors](https://business.facebook.com/business/help/2041876302542944)).
8. «Productos»: every product reads «Cumple los requisitos».
9. Back on the connector screen, "Meta last downloaded the catalog" must show a date.

Below the steps, one line tells the client that the category, sale and brand labels of the feed
([Meta feed format](#meta-feed-format)) are there to build product sets and campaigns by category.

When the client regenerates the URL (manual mode), the new one goes in the feed's **Settings** tab in Data sources. If several
scheduled fetches fail, Meta pauses the schedule: after fixing the cause, **Resume** it from the same tab.

## Connect with Facebook (v2)

The client clicks **Connect with Facebook** on the Meta detail, ticks a catalog in Meta's dialog, and Ruweb keeps
that catalog up to date: no URL to paste. The connection is stored encrypted in the `secrets` of the `meta` row
(`App\Connectors\Meta\Connect\MetaConnectionStore`, bound to the site origin like the Meta Pixel token); the panel
and MCP only ever show the last 4 characters of the token.

### Requirements

- **A «Productos online» (commerce) catalog** created by the client in Commerce Manager, or an existing one. Ruweb
  cannot create it: Meta refuses catalog creation by API for this kind of token, and the dialog's own «Crear
  catálogo» makes a `generic` catalog that cannot be used. A non-commerce catalog is refused with steps to create
  the right one.
- **The RugerTek Connect broker**: `RUWEB_CONNECT_URL` in `.env` ([ruweb-connect.md](ruweb-connect.md)). Empty
  (the default): the button is disabled with an explanation and the manual guide stays.
- **A server that answers two requests at once.** While the panel waits for the broker to register the site, the
  broker reads `/.well-known/ruweb-connect/{token}` from the same site. `php artisan serve` handles one request at a
  time: set `PHP_CLI_SERVER_WORKERS=2` (or more) for local tests; production behind php-fpm (or any multi-worker
  server) is fine.
- The product catalog module on (the same requirement as the manual feed), and the queue worker for retries
  ([Synchronization](#synchronization)).

### Connect, choose the catalog, disconnect

Routes in `routes/connectors-admin.php` (administrators only), controller
`App\Http\Controllers\Admin\MetaConnectController`:

1. **Connect** (`admin.connectors.meta.connect`): the first time, the site registers with the broker (it serves the
   challenge at `/.well-known/ruweb-connect/{token}` for 10 minutes and receives a site secret, stored encrypted),
   then sends the browser to the broker's signed start link with a one-time nonce kept in the session for 10
   minutes.
2. Meta's dialog: the client logs in, picks the business portfolio and ticks the catalog.
3. **Back** (`admin.connectors.meta.connected`): the nonce must match, belong to the same administrator and be
   fresh; the site collects the token from the broker once (server to server; one signed retry on a network
   failure). Cancelling in Facebook is a friendly message that changes nothing.
4. **Catalog**: with exactly one commerce catalog it is chosen at once; with several the administrator chooses
   (`admin.connectors.meta.catalog`, only commerce catalogs are offered); with none the panel explains how to create
   one. Choosing provisions the sync (`MetaCatalogSync::provision()`) and activates the connector. "Change catalog"
   reads the shared catalogs from Meta again. Reconnecting the same business keeps its catalog.
5. **Disconnect** (`admin.connectors.meta.disconnect`): drops the token and the connection state and goes back to
   the manual mode. Optionally ("also delete the feed Ruweb created on Meta") the `Ruweb – <site>` feed is deleted in
   the catalog; a Meta failure there is only logged. **Never deleted**: the client's catalog, its products, the
   connector and its feed URL (if the Meta feed is kept, Meta keeps reading it while the URL works), and the
   broker site credentials. To remove Ruweb's access entirely the client also removes the app in Meta's business
   settings (the panel links there).

### States

| `meta_connection.mode` / state | Meaning | Panel |
|---|---|---|
| `manual` | v1: no connection, the client pastes the feed URL | Commerce Manager guide + "Connect with Facebook" when the broker is configured |
| `connected` | Token and (once chosen) catalog; Ruweb syncs | Catalog, business, since when and by whom, "termina en …"; Change catalog; Disconnect; the "Sync with Meta" block. The manual guide is hidden |
| `needs_reconnect` | Meta rejected the connection explicitly ([Revocation](#revocation-and-needs_reconnect)); the token was dropped | Red notice + "Connect again"; the card status is `needs_reconnect` |

### Synchronization

`App\Services\Connectors\Meta\MetaCatalogSync`:

- **Feed by API.** On provisioning, Ruweb creates (or reuses) a scheduled feed named `Ruweb – <site>` in the chosen
  catalog with schedule `HOURLY` pointing at the site's feed URL and the shop currency, then asks Meta for an upload
  right away, so the catalog fills within minutes instead of waiting for the first hour.
- **Retries.** When Meta does not answer, the step is queued as `App\Jobs\SyncMetaCatalogFeed` with backoff 60, 300,
  900 and 3600 seconds (5 tries). With `QUEUE_CONNECTION=sync` there is no waiting: the failure is shown and the
  administrator retries with "Sync now".
- **"Sync now"** (`admin.connectors.meta.sync`): makes sure the feed exists and points at the current URL, then
  asks Meta to read it. At most once a minute (route throttle 6/min + a 60 s cooldown).
- **Regenerating the feed URL updates Meta** (`ConnectorOperations::rotateToken()` → `refreshFeedUrl()`): the
  schedule URL changes, nothing to paste.
- **Status** in the detail: last read, products loaded, and what Meta flagged (upload errors and warnings plus the
  item problems of the last batches, up to 20), cached 5 minutes (1 minute when Meta did not answer).

### Changes at once (items_batch)

The hourly feed is the source of truth (it replaces the whole catalog); `App\Services\Connectors\Meta\MetaItemsPublisher`
is the fast path:

- **What triggers a batch** (`App\Listeners\Connectors\MetaCatalogChanges`): saving or deleting a product
  (including publishing, unpublishing, price, SKU, has-variants changes), saving or deleting a variant, an order
  whose stock is applied or given back (payment, cancellation, refund), and replacing or deleting an image.
- **Debounce 60 s**: the first change queues `App\Jobs\PublishMetaItems` with a 60-second delay; later changes join
  the same batch. On the `sync` queue driver the batch goes out after the response.
- **One `items_batch` call** (`PRODUCT_ITEM`) with UPDATE for every item the feed would include (same fields as the
  feed, `App\Connectors\Meta\MetaItemFields`; Meta creates missing items) and DELETE for what left (unpublished,
  deleted, an old SKU or variant, or now skipped). Only the changed products are read
  (`ShopProductSource::itemsFor()`), never the whole catalog. `requests` travels form-encoded as one JSON string,
  as Graph expects.
- **What only arrives with the hourly feed**: scheduled publication (a product whose publish date arrives fires no
  save) and category or brand changes (taxonomy edits do not touch the product row).
- **Limits and backoff**: at most 3000 requests per call (bigger batches are split); when Meta's usage header asks
  to slow down, a pause of at least 60 s; rate-limit codes (4, 17, 32, 613, 80009, 80014) and outages keep the
  pending products and retry after 60, 300, 900 and 3600 seconds; when the retries run out the panel says Meta did
  not answer and the next change (or the hourly feed) carries them.
- **Per-product notices**: `App\Jobs\CheckMetaItemsBatch` reads `check_batch_request_status` (backoff 10 s to 15
  min) and its errors **and warnings** (Meta reports an invalid price as a warning) are shown in the detail with an
  "Edit product" link, until that product is sent again. Problems of DELETE requests are not shown.
- Only ids and handles sit in the cache and the queue: the token is read when the call is made.

### Daily health check

`php artisan meta:health-check` (`App\Console\Commands\MetaHealthCheckCommand`) is scheduled daily in
`routes/console.php`, so **the scheduler must run** (`php artisan schedule:run` every minute from cron). In connected mode it reads the token (`/me`), the chosen catalog and the Ruweb
feed (a feed deleted in Commerce Manager is flagged, not recreated: "Sync now" does that).

- An explicit rejection → `needs_reconnect` ([Revocation](#revocation-and-needs_reconnect)) and **one** email
  "Reconnect your Meta catalog" to every administrator with a valid address (or the contact email when there is
  none).
- **Meta not answering never drops the connection.** A day without an answer is recorded as the last error ("we
  will try again tomorrow"). From **3 consecutive days** on (`App\Services\Connectors\Meta\MetaUnreachableStreak`),
  the detail shows "Meta has not answered for N days; check the server's connection" and the administrators get
  one email for the whole streak; the token stays and everything resumes by itself the first day Meta answers (the
  warning disappears and a later streak emails again). A day without a run breaks the streak.
- **Emails need mail turned on** (`mail_enabled` in Settings, same mailer and queue as the order emails); otherwise
  only a log line is written.

### Revocation and needs_reconnect

Only Meta's explicit answers move the connection to `needs_reconnect`, whoever sees them (health check, sync,
items_batch):

- **190 (any subcode, e.g. 464 when the client removed the app) or 102**: the token is no longer valid.
- **100 with subcode 33 on the chosen catalog**: the client removed Ruweb's access to that catalog.

The token is dropped, the business and catalog are kept so the panel can say what was connected, pending changes
are discarded (the next feed carries everything after reconnecting) and the administrators get the email once per
connection. `debug_token` cannot detect a revocation, so it is not used.

### Permissions and the pixel

The Meta app asks only for `catalog_management` (plus `public_profile`); no `ads_management`. The Meta Pixel stays
configured by hand with its own token ([Meta Pixel card](#meta-pixel-card)): the catalog token cannot read the
pixel's dataset.

## Meta Pixel card

The Meta Pixel and Conversions API connector (`meta_pixel`, v1.1) is in the "Advertising and measurement" group and
works with the shop off. Unlike the feed, its settings (pixel ID, encrypted Conversions API token, test events code
and the "Send test event" button) live on its own detail, `/admin/connectors/meta_pixel`; the card shows the status
and whether the pixel ID is missing. Browser and server events share an `event_id` so Meta deduplicates them, and
their `content_ids` are the feed's `g:id`. Everything else: [meta-pixel.md](meta-pixel.md).

## Amiba chatbot card

The Amiba chatbot connector (`amiba_chat`, level 1, `App\Connectors\AmibaChat\AmibaChatConnector`) is in the
"Customer service" group (`ConnectorCategory::CustomerService`), always visible and independent of the shop. The
client creates the chat in Amiba (RugerTek's chatbot platform), pastes its code on the detail
(`/admin/connectors/amiba_chat`), presses **Test**, activates it, and the floating chat shows on the public pages.
Nothing changes on the Amiba side.

- **Widget code**: the field accepts the handle alone (Amiba's "Site key"), the floating widget snippet
  `<script src="https://widget.amiba.io/loader.js" data-amiba-widget="HANDLE" async></script>` or the iframe embed
  (`https://widget.amiba.io/w/HANDLE`); `AmibaChatConnector::normalizeSettings()` keeps only the handle, in lowercase,
  for the panel and MCP alike. Amiba creates handles as a version 7 GUID (`Guid.CreateVersion7()` in andromeda-api),
  so anything else is refused with `amiba_chat.error_handle_format`. Other loader attributes (`data-api`, `data-base`)
  only point the widget at a non-production Amiba and are dropped; position, colour, title and language are set in
  Amiba, not in the snippet. The handle is public (it is in the HTML of every page): it is no secret.
- **Settings**: `handle` and `hide_on_checkout` (default `false`). The only requirement is a valid handle
  (`amiba_handle`): without it the card is `needs_action` and activation is refused.
- **Where it loads**: `resources/views/partials/amiba-chat.blade.php`, in the `<head>` of `layouts.public` next to the
  Meta Pixel, renders Amiba's snippet (`async`, attributes escaped) while the connector is on with a handle
  (`App\Services\AmibaChat\AmibaChat::handleFor()`). Never on `/admin` screens nor inside the panel's previews
  (`$isEditorPreview`). Unlike the pixel it also shows to someone signed in to the panel: a chat measures nothing.
  With `hide_on_checkout` it is left out of the checkout and the order (payment) pages
  (`shop.checkout.*`, `shop.order.*` and their `localized.` twins); the cart and product pages keep it.
- **Test**: `AmibaChatOperations::test()` asks Amiba from the server, with a timeout
  (`cms.amiba_chat.timeout`), for `GET {cms.amiba_chat.api_url}/webchat/{handle}/config`, the public call the widget
  itself makes. A 404 means the handle does not exist or its Web Chat is off. A 200 brings the widget's
  `allowedOrigins`: Amiba accepts any site when the list is empty and otherwise an exact `scheme://host[:port]` match,
  checked when a visitor's chat starts (`POST /webchat/{handle}/session` answers 403 `origin_not_allowed`). The test
  compares this site's origin (`APP_URL`, normalized) with that list and, when it is missing, tells the client the
  exact line to add in Amiba ("Web Chat" node › "Widget settings" › "Allowed origins"). A 403 `origin_not_allowed`,
  a timeout and any other answer are explained too. The test writes nothing; the route allows 6 presses a minute.
- **Guide on the detail**: in Amiba, the agent's "Web Chat" node ("Connect Web Chat" if needed) › "Embed on your
  website" › "Floating widget" › "Copy"; then "Widget settings" › "Allowed origins" with the site origin shown on the
  screen. Labels copied from Amiba's panel code (andromeda-crm `i18n/locales`) on 2026-11-25, not checked on a live
  Amiba screen.
- **Content Security Policy**: the base sends no CSP on public pages. A site that adds one must allow
  `https://widget.amiba.io` in `script-src` and `frame-src` while the connector is on.
- **MCP**: `connectors_list` and `connectors_get` return an `amiba_chat` block (handle, whether it is on the public
  pages, the loader URL and the origin to allow in Amiba); `connectors_update` changes the handle (a pasted snippet
  works) and switches it on or off ([mcp.md](mcp.md#connectors)). The Test runs only in the panel.
- **Config**: `cms.amiba_chat.widget_url` (`https://widget.amiba.io`), `cms.amiba_chat.api_url`
  (`https://api.amiba.io`, the API the production widget uses) and `cms.amiba_chat.timeout`.

## Planned connectors

Nothing below exists in the code yet; each item names what it would reuse.

- **Connect with Facebook in production**: the code is done ([Connect with Facebook (v2)](#connect-with-facebook-v2));
  real clients need the production Meta app in RugerTek's verified business portfolio, advanced access through App
  Review (weeks, outside our control) and the broker hosted on a public HTTPS domain.
- **Per-language and per-country feeds**: one feed per public language or market, from the translated product rows
  (today only the base language is sent).
- **Google Merchant Center**: same source, same `FeedResult`, its own writer and defaults.
- **Amiba, level 2: the bot reads the shop**: Amiba's assistant answers about products, prices and stock by calling
  the site's MCP (`products_*`) with a read-only key the site creates for it. Needs, on the Amiba side, a way to
  register that MCP server and key per agent; on the Ruweb side, a read-only key bound to the connector.
- **Amiba, level 3: «Connect with Amiba»**: one button instead of pasting the widget code; Ruweb creates or picks the
  widget and adds the site to its allowed origins by itself. Needs an Amiba API with OAuth (or a broker like RugerTek
  Connect) that does not exist yet. Level 1 (pasting the code) is done: [Amiba chatbot card](#amiba-chatbot-card).
- Onsite checkout fields (paying inside Facebook or Instagram) are not planned: the purchase ends on the Ruweb site.
