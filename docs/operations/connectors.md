# Connectors

> Admin > Connectors: how a site hands its catalog to another platform, how to add a connector, and the Meta
> (Facebook and Instagram) catalog feed with what the server and the client must do for Meta to read it.

A connector links the site to an external platform without code: the administrator opens **Connectors** in the
panel, turns a connector on and follows the guide on its screen. The first one is **Meta**: a product feed that
Commerce Manager downloads on a schedule, so the shop's products appear in the catalog used by Facebook and
Instagram shops and catalog ads. v1 is feed-only (Meta pulls a URL); there is no Catalog API push, Pixel or
"Connect with Facebook" login.

## What the administrator sees

Rule: **Connectors is the showcase and the status of everything external (sales channels and payment methods); the
settings of each one live in a single place** (Meta on its detail, Bancard in Admin > Payments and shipping), never
duplicated on the card.

- **Connectors is always in the sidebar for administrators**, whatever modules are on: it is how a client finds
  out what the site can connect to. Editors do not see it and its routes answer 403 (`role:admin` group of
  `routes/admin.php`, routes in `routes/connectors-admin.php`). It is an operational screen, valid in both admin
  profiles ([../concepts/admin-profiles.md](../concepts/admin-profiles.md)).
- `/admin/connectors` is a grid of cards, one per registered connector, grouped under a heading per
  `Connector::category()` (`App\Connectors\ConnectorCategory`, in the order of its cases: "Sales channels" for
  Meta, "Payment methods" for Bancard; empty groups are not shown), each with its status
  (`App\Connectors\ConnectorStatus`):

  | Status | When | The card offers |
  |---|---|---|
  | `available` | Requirements met, connector not active | Published product count (and the feed item count when variants make it differ), how many would enter the feed, and Activate |
  | `needs_action` | A requirement is unmet (for Meta: the product catalog is off), even if the connector is active; the chip reads "Paused" for an active connector | What is missing and, when the administrator can fix it, the button that does (for Meta: turn on the product catalog, through `ShopModuleOperations::setMode()`, see [../shop/catalog.md](../shop/catalog.md)) |
  | `connected` | Active and every requirement met | Included and skipped counts of the last generated file, last download by the platform (for Meta, "never downloaded" until Meta fetches it) |

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
| Registry entries | `config/cms.php`: `connectors` (key => class) and `catalog_sources` (key => class) | Which connectors and sources exist. Today `meta` and `bancard`, and the `products` source. |
| `Connector` | `app/Contracts/Connectors/Connector.php` | `key()`, `label()` and `description()` (translation keys), `icon()` (Blade view), `category()` (`ConnectorCategory`: grid group), `requirements()`, `defaults()`, `rules()` |
| `FeedConnector` | `app/Contracts/Connectors/FeedConnector.php` | Extends `Connector` for platforms that download a file: `source()`, `check()`, `write()`, `contentType()`, `extension()`, `fetchAgents()` |
| `CatalogSource` | `app/Contracts/Catalog/CatalogSource.php` | Where a feed reads items from: `key()`, `available()`, `items()` (a cursor-backed `LazyCollection`, never the whole catalog in memory), `count()` (items) and `groupCount()` (products: the variants of one product count once) |
| `CatalogItem` | `app/Connectors/CatalogItem.php` | Immutable item handed from source to connector: id, group id, title, plain-text description, relative URL, media ids, `Money` price and sale price, availability, brand, variant attributes, panel edit URL |
| `ConnectorRequirement`, `ConnectorAction` | `app/Connectors/` | What a connector needs from the site and the panel button that resolves it (`ConnectorRequirement::shopCatalog()`) |
| `FeedWarning`, `FeedResult` | `app/Connectors/` | Why an item is skipped or only flagged; the totals, sample and warnings of a run |
| `ConnectorRegistry` | `app/Connectors/ConnectorRegistry.php` | Read side: resolves classes on every call, status, effective settings (defaults + stored), and `feedRecord()` that decides whether a public feed request gets a document or a 404 |
| `Connector` model | `app/Models/Connector.php`, table `connectors` | One row per connector, created on first activation: `enabled`, `settings_json`, token hash and encrypted token, fetch log and counters |
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
format. v1 ships only the shop products source (`products`).

The next planned connector is Google Merchant Center: same source, same `FeedResult`, its own writer and defaults.

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
- **Regenerate** invalidates the old URL at once: the client must paste the new one into Commerce Manager, or
  Meta's next scheduled fetch fails.
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

Sources consulted on **2026-11-23** (dates are the "Updated" line of each page; the Business Help Center pages
carry no date):

| Topic | Source |
|---|---|
| Accepted formats, one field per line in XML | [Catalog reference](https://developers.facebook.com/documentation/ads-commerce/catalog/reference/) (Updated: Apr 22, 2026) |
| Fields and their rules | [Catalog fields](https://developers.facebook.com/documentation/ads-commerce/commerce-platform/catalog/fields) (Updated: Oct 5, 2026) |
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
| `color`, `size`, `gender`, `pattern`, `material` | Variant attributes with those keys (case-insensitive), also under the Spanish names `talle`, `talla`, `tamaño` → `size` and `estampado` → `pattern`, and `colour` → `color`. `gender` only when the value is `female`, `male` or `unisex` (sent in lowercase); any other value goes to `additional_variant_attribute` | ≤ 200 |
| `additional_variant_attribute` | Remaining variant attributes as `Key:Value,Key:Value` (no space after the comma; `:` and `,` inside a key or value become spaces), up to 3 pairs | Up to 3 pairs |

Items left out (listed in the panel with their reason and an edit link): `missing_price`, `missing_image`,
`image_not_convertible` (the image could not become a JPEG) and `no_variants` (variants enabled but none exists).
Notices that keep the item: `default_brand`, `image_too_small` (shorter side under 500 px; Meta decides),
`title_truncated`, `description_from_title`.

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
2026-11-23 in English. The Spanish labels of Meta's interface are not verified yet: correct them against real
screenshots the first time a catalog is connected.

0. Requirement: a Meta business portfolio (Business Manager) with full control.
1. Open [Commerce Manager](https://business.facebook.com/commerce_manager). Without a catalog, create one: type
   **Online products**, no platform connection, choose the portfolio and a name; the pixel can be skipped.
2. In the catalog: **Catalog** tab → **Data sources** → **+ Add** → **Data file**.
3. In "Which platform is your file formatted for?" keep **Commerce Manager**.
4. Choose **Use a URL** and paste the feed URL copied from the connector screen.
5. Next. If Meta offers to map fields, nothing needs changing: the feed uses Meta's field names.
6. Name the feed and choose the site currency (**PYG** by default, `cms.shop.currency`) as default currency.
7. Schedule: **daily** (Meta fetches at most once an hour:
   [scheduled feeds](https://developers.facebook.com/documentation/ads-commerce/catalog/guides/scheduled-feeds/)).
   Keep **Replace**, so a product that is unpublished also leaves the catalog.
8. **Upload**. Data sources shows how many products entered and the errors
   ([Troubleshoot data feed errors](https://business.facebook.com/business/help/2041876302542944)).
9. Back on the connector screen, "Meta last downloaded the catalog" must show a date.

When the client regenerates the URL, the new one goes in the feed's **Settings** tab in Data sources. If several
scheduled fetches fail, Meta pauses the schedule: after fixing the cause, **Resume** it from the same tab.

## Not in v1

- **"Connect with Facebook"** (Meta Business Extension: login, catalog chosen in a dialog, products pushed by API)
  needs a Meta app owned by a verified business, advanced access through App Review and one central app for
  every site. It would reuse `CatalogSource` and `FeedResult` with a different writer.
- Per-language or per-country feeds, Pixel and Conversions API, and onsite checkout fields.
