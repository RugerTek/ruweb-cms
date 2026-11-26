# Shop module: catalog

Optional product catalog. Not every site sells: the shop tables always exist (empty on a site without a shop),
but with the shop off a site has no shop pages, admin menu, MCP tools or registry entries. The administrator turns
it on and off from the panel. This doc owns the switch, its modes and what each mode adds.

## The switch

Two values decide the mode, both read only through `App\Support\ShopModule` (`app/Support/ShopModule.php`):

| Value | Where | Who changes it |
|---|---|---|
| `shop_mode` | `settings` table, group `shop` (`App\Support\SettingDefaults`, starts `off`) | The administrator: Settings > Shop, `settings_update` over MCP, `cms:install --shop` |
| `shop.mode` | `config/cms.php` | The deployment, versioned. It is the **limit**: `off` (the site cannot turn the shop on), `catalog` (up to the catalog) or `checkout` (no limit, the default). No environment override. |

`ShopModule::mode()` is the stored choice lowered to the limit: a site limited to `catalog` whose setting says
`checkout` runs as `catalog`. `catalog()` is true for `catalog` and `checkout`, `checkout()` only for `checkout`;
`limit()`, `stored()`, `allows($mode)` and `path()` complete the API. An invalid value throws a `LogicException`
naming `cms.shop.mode` (config) or the `shop_mode` setting on first read. While the `settings` table or the row does
not exist yet (fresh install, `migrate` in progress, artisan without a database) the mode is `off`. The value is
read through `Setting::get()`, cached for the process like any setting.

| Mode | Adds |
|---|---|
| `off` | Nothing. `/productos` is an ordinary page slug. |
| `catalog` | Products (with or without price), public catalog, sitemap entries, admin screens, MCP tools, menu links. No cart. |
| `checkout` | Everything in `catalog`, plus the "Add to cart" button, cart, checkout, order page, orders panel and payments (see [checkout.md](checkout.md)). It can only be chosen once the site meets every selling requirement (`CheckoutReadiness`: a ready payment method, a delivery option, an orders email and email sending on); until then the panel shows the option disabled with what is missing and the operation refuses it. |

One enum instead of two booleans: "checkout without catalog" cannot be expressed.

Other keys of the same block: `currency` (ISO 4217, one per site, `PYG` by default; amounts are integers),
`path` (public prefix, `productos`), `taxonomy_types` (`brand`), `collection` and `content_schema` (the
registry entries merged while the catalog is on).

## Turning it on and off

**Settings > Shop** (`admin.settings.shop`, administrators only; a link next to the Settings tabs while the limit
is not `off`) lists the modes the limit allows. Choosing one opens a confirmation that says what changes; turning
the shop off also says how many products stay stored (base-language rows, the same total as "All" in the product
list) and lists the active connectors that read the catalog (`ConnectorRegistry::activeNeedingCatalog()`): their
feeds answer 404 while the shop is off and the platform may pause its scheduled upload
([../operations/connectors.md](../operations/connectors.md)). Only the confirmed request switches it.

The single write path is `App\Services\Shop\ShopModuleOperations`:

```php
// Admin only (403 otherwise). ValidationException on `mode` when the mode is unknown, above cms.shop.mode or
// not delivered yet, or for `checkout` while CheckoutReadiness reports something missing (one message listing
// every missing requirement). Stores shop_mode, re-syncs this process's registries and mirrors the Bancard
// connector card; never touches shop data.
public function setMode(User $actor, string $mode): void;

// Modes within the limit, in order, with `available` false for the ones not delivered yet and, for `checkout`,
// the unmet selling requirements (each with the panel route that resolves it).
/** @return list<array{mode: string, available: bool, missing: list<ReadinessItem>}> */
public function choices(): array;
```

The panel, `settings_update` / `settings_update_batch` (`shop_mode` is in `cms.mcp.settings_writable_keys`; the
generic `SettingOperations::persist()` hands the key to `setMode()`), `settings_reset` (back to `off`) and the
installer all go through it, so the limit cannot be bypassed. Other features reuse it to offer the shop where it
matters (for example a connector card's "turn on the product catalog" button).

New install:

```bash
php artisan cms:install --shop=catalog      # off | catalog | checkout; omitted = keep the current choice
```

The installer validates the value against the modes and the limit before touching anything, migrates as usual
(the shop tables are ordinary migrations) and stores the choice with `setMode()` as the installed administrator.
`config/cms.php` is not written.

Nothing else is needed: no `migrate`, no cache clear, no restart. The shop routes are always registered, so a
route cache built while the shop was off keeps working when it is turned on (see below). Long-running processes
(queue workers) read settings once per process, like any setting: restart them to pick up the change there.

**Turning it off deletes nothing.** The products stay, media used by products stay protected from deletion, and
turning the shop back on shows everything as it was. Menu items that link a product stay stored and are not
rendered publicly while the shop is off. Saving such a menu (panel or `menus_sync_items`) keeps them without
checking them against the catalog; only adding a new product item is refused until the shop is on again
([menus.md](../content/menus.md)).

Snapshots carry the `shop_mode` setting like any other setting, and the shop tables exist on every site, so a
snapshot taken with the shop on restores anywhere.

## Routes and route:cache

The shop's routes are registered whatever the mode, because deploys run `php artisan route:cache`
([../operations/update-recovery.md](../operations/update-recovery.md)) and a cached route list cannot change when
an administrator flips a setting. `App\Http\Middleware\EnsureShopCatalog` decides per request:

- Panel (`routes/shop-admin.php`) and MCP REST (`routes/mcp-shop.php`) routes answer 404 while the catalog is off,
  as if they did not exist.
- Public catalog routes (`shop.*`, `localized.shop.*`, middleware parameter `page`) hand the request to the page
  catch-all instead: the middleware binds `page.show` / `localized.page.show` to the request and runs it, so
  `/productos` is an ordinary page (or a 404) with the shop off. The shop prefix is therefore never part of the
  catch-all's reserved slugs.
- Routes with a `{type}` constraint that the shop extends (`admin/editorial/{type}`, editor locks,
  `admin/taxonomies/{type}`, `mcp/content/{type}/…`) compile the shop's types in
  (`ShopModule::editorialTypes()`, `taxonomyTypes()`) and `App\Http\Middleware\RequireRegisteredType` answers
  404 when the type is not registered right now.

MCP tools need nothing special: `ToolRegistry`, the dispatcher and `EnforceMcpScopes` read the mode per request.

## What appears with the catalog on

| Surface | Mechanism |
|---|---|
| Registry | `ShopServiceProvider` merges `shop.collection` into `cms.collections.products`, `shop.content_schema` into `cms.content_schemas.product` and `shop.taxonomy_types` into `cms.taxonomy_types`. Everything that walks those registries (create bar, Essential operational list, `cms_capabilities`, `McpSurfaceContractTest`) picks products up. Turning it off removes only what the provider added. |
| Public routes | `routes/shop.php`, required from `routes/web.php` before the page catch-all, in the base group (`shop.*`, `/productos`) and under `{locale}` (`localized.shop.*`, `/en/productos`). With the shop off they hand the URL to the page catch-all ([Routes and route:cache](#routes-and-routecache)). |
| Admin | `routes/shop-admin.php`, grouped from `routes/admin.php` inside the authenticated admin group behind `EnsureShopCatalog`. In `full` the sidebar shows a Shop group (products, brands) and `App\Http\Controllers\Admin\ProductController` is the product CRUD: form preview, History link, language switcher that opens or creates a translation (`admin.products.translate`). In `essential` products appear in the operational content list like any registered collection and the CRUD answers 404 ([collections.md](../content/collections.md)). |
| MCP | `products_*` tools with the same shared operations as the panel, plus the `content_*` editorial tools with `type: products`. `products_list` / `products_get` are reads only while the catalog exists, so they are added by `EnforceMcpScopes::reads()` instead of `READ` ([mcp-surface.md](../operations/mcp-surface.md)). |
| Discovery | `AdminCapabilities::discovery()` reports `modules.shop` with the effective mode, for `php artisan cms:capabilities`, `cms_capabilities` and `GET /mcp/capabilities`. |
| Menus | Menu items of type `product` link a product in the menu's language ([menus.md](../content/menus.md)). `MenuOperations::linkableTypes()` in `app/Services/Menus/MenuOperations.php` is the single list of linkable types; it contains `product` only while the catalog is on (see [Turning it on and off](#turning-it-on-and-off) for stored items). |
| Media | Featured image and gallery are `mediables` rows; media embedded in a product's rich text or `meta_json` also counts as used (`app/Services/Media/MediaUsage.php`). |
| Custom code | `shop.custom_code_index` is merged into `cms.custom_code.indexes.shop`, a destination for code scoped to the catalog listing (`ShopController::index` sets it as the page's custom-code target, per language). Product pages are ordinary `products` destinations. |
| Editorial | `shop.collection` declares `editorial_history`, so `products` is an editorial type (`App\Support\EditorialTypes`): see below. |

## Categories and brands

A product carries two kinds of terms, both managed in the panel (Taxonomies) and through `taxonomies_*`:

| Term | Type | Shared with | Used for |
|---|---|---|---|
| Brand | `brand` (`Product::BRAND_TAXONOMY`, merged from `shop.taxonomy_types`) | Products only | Product list filter, Meta feed `brand` and `custom_label_2` |
| Category | `category` (`Product::CATEGORY_TAXONOMY`, the base `cms.taxonomy_types` entry) | Blog posts | Meta feed `product_type` and `custom_label_0` |

Categories nest through `parent_id`. The product form lists the categories of the product's language in tree order,
each with its full path (`Ropa > Remeras`), so a subcategory is clear without indentation; a term whose parent is
missing or part of a loop is listed at the top level. MCP `products_create_draft` / `products_update_draft` /
`products_update` take `categories` as a list of term ids (find them with `taxonomies_list`, `type: category`) and
`products_get` returns them, the same shape as `brands`.

- **Validation** (`App\Services\Products\ProductInput`): every id must be a `category` term of the product's
  language, at most `ProductInput::MAX_CATEGORIES` (20), no repeats; a brand id or a term of another language is
  refused with `shop.category_invalid`.
- **Writes** go through `ProductOperations::save` like brands: a full save (panel form) replaces the list and an
  empty selection removes them; a patch (MCP) changes them only when `categories` is sent, and `[]` removes them.
  Only the `category` terms are replaced, so brands are untouched and vice versa.
- **History.** Terms are part of the product version (`EditorialHistory::payload` stores `taxonomies`), so changing
  categories is a new version with the acting user, changes the optimistic revision, and restoring a version brings
  its categories back. An editor's change to a published product is staged as a proposal with its categories
  (`ProductPreview`), and `content_propose` accepts `categories`.
- **Meta feed.** The first category (lowest id) and its parents become `product_type` (`Ropa > Remeras`) and the
  top level becomes `custom_label_0` (`Ropa`); without a category both fields are left out
  ([../operations/connectors.md](../operations/connectors.md)).

## Products as editorial content

Products share the editorial workflow of posts: history, versions, restoration, proposals and reviews, shared
`/draft-preview/<token>` links, translation and the `content_*` MCP tools (`type: products`), plus `cms_schema`.
In the panel the product form links to the shared history screen (`/admin/editorial/products/{id}`), where versions,
pending proposals, reviews and shareable preview links live, as for posts; restoring a version there returns to
`admin.products.edit`. The product form itself is a regular admin page (`admin.layouts.app`), not the posts editor
shell: it has no edit lock or draft recovery.

- **Versions hold the variants.** A product version (`App\Services\Editorial\EditorialHistory::payload`) stores
  its attributes, media, terms and the variant list; money columns are stored as integer amounts. A change that
  only touches variants is a new version, and the optimistic revision (`ProductOperations::revision()`, the same
  value `content_*` returns) is the editorial fingerprint.
- **Restoring and approving go through `ProductOperations::save`.** Prices pass through the Money cast and the
  variant list is rebuilt: variants deleted since that version are created again (new ids). A version that would
  reuse a SKU now taken by another product fails validation instead of overwriting it.
- **Proposals.** An editor's change to a published product (panel save or `content_propose`) is stored as a
  proposal version; an administrator applies it by approving the review or discards it by requesting changes.
  `content_propose` accepts the product form fields except status, locale and publication dates.
- **Shared preview.** `App\Services\Products\ProductPreview::link()` pins the saved state (or unsaved changes as a
  proposal) and returns the confidential URL; `revoke()` ends it. `EditorialPreviewController` renders the pinned
  version with its own variants.
- **Translation.** `content_translate`, the panel language switcher (`POST /admin/products/{id}/translations/{locale}`)
  and `TranslationOperations::create` copy the translatable fields (`title`, `excerpt`, `description`,
  `meta_json.title`/`description`), media, brands and categories remapped to the target language, as a draft. Commerce fields
  (sku, prices, stock, variants) are not copied: they live on the base row, and the translation's form hides them.

With the catalog off products are not an editorial type: those screens and tools answer 404 for `products`.

## Sell online

`checkout` adds the cart, the checkout, the order page, Admin > Orders and online payments on top of the catalog.
The flow is in [checkout.md](checkout.md), payment methods and Bancard in [payments.md](payments.md), delivery in
[shipping.md](shipping.md).

**Settings > Shop** offers "Catalog + selling" disabled, listing what is "Still missing", until the site meets every
requirement of `App\Services\Shop\CheckoutReadiness` (`app/Services/Shop/CheckoutReadiness.php`). Each missing item
links to the screen that fixes it:

| Requirement | Met when | Fixed in |
|---|---|---|
| `payment_method` | At least one payment method enabled **and** complete: bank transfer with its bank details, cash on delivery with its text, or Bancard with keys of its environment readable on this site | Admin > Payments and shipping |
| `delivery` | Store pickup on with an address, or at least one enabled delivery zone | Admin > Payments and shipping |
| `orders_email` | A valid address for order notices: `shop_orders_email`, or `contact_email` when it is empty | Admin > Payments and shipping |

Sending email is **not** a requirement: a shop sells without it, with a notice in the panel and an order page that
carries everything the buyer needs ([checkout.md](checkout.md#sin-email)). `setMode('checkout')` refuses the switch with the same list
from every entry point (panel, MCP `settings_update`, `cms:install --shop=checkout`), and `choices()` returns it for
the panel. `shop_orders_email` is also writable over MCP (`settings_update`), with email validation; while selling, a change
that leaves no valid orders address (it or `contact_email`) is refused.

Admin > Payments and shipping is available as soon as the catalog is on, so everything can be prepared before
selling is turned on. While selling, it refuses a change that would leave the checkout without a payment method or
delivery option. Turning selling off keeps orders manageable, the order pages working and the Bancard webhook
listening ([checkout.md](checkout.md#turning-selling-on)).

With `checkout` on, the product page shows **Add to cart** (variant and quantity; disabled without a price or
stock; never in the editor preview), the header shows the cart counter, and `carrito`, `checkout` and `pedido` are
reserved: products cannot use them as slugs (`ShopModule::RESERVED_SLUGS`).

## Show the catalog on Meta

The Meta connector publishes the catalog as a product feed that Commerce Manager downloads on a schedule, so the
products appear in Facebook and Instagram shops and catalog ads. It lives in **Connectors**, not in the shop: the
section is always visible to administrators, also with the shop off.

- **Shop off.** The Meta card says the catalog is needed and, when `shop.mode` allows it, offers **Turn on product
  catalog**. Confirming calls `ShopModuleOperations::setMode($actor, 'catalog')` through
  `ConnectorOperations::enableCatalog()`, the same path as Settings > Shop; it never lowers `checkout`. With the
  limit at `off` the card only explains.
- **Catalog on.** The administrator activates the feed, copies its secret URL and follows the Commerce Manager
  guide on the connector screen. Products enter the feed as they are published in the base language; each one
  needs a price and a featured image (WebP images are sent to Meta as JPEG copies). The screen lists the products
  left out with the reason and an "Edit product" link.
- **Shop turned off later.** The feed answers 404 and the card asks to turn the catalog on again; the connector
  keeps its settings and URL. Turning the catalog back on serves the feed again; if several scheduled fetches
  failed meanwhile Meta pauses the schedule, and the client resumes it from the feed's Settings tab in Commerce
  Manager.

Saving or deleting a product, variant or media item marks the feed stale, so Meta's next fetch gets the change.
Feed format, field mapping, the URL and token, what the web server and Cloudflare must allow, and the full
guide: [../operations/connectors.md](../operations/connectors.md).

## Tests

`Tests\TestCase::useShop('off'|'catalog'|'checkout', $limit = 'checkout')` writes the `shop_mode` setting directly
(so `checkout` works before it is delivered), sets the config limit and re-syncs the registries; routes need no
rebuild. `tests/Feature/ShopModuleSwitchTest.php` covers Settings > Shop (confirmation, administrators only), the
limit, MCP `settings_update` / `settings_reset`, turning off without losing products, the missing `settings` table
and a route cache compiled with the shop off; `ShopModuleTest` covers the switch, discovery, the installer, menus and media;
`ShopPublicTest` the public catalog, sitemap and the catalog custom-code index; `AdminProductsTest` the panel
(CRUD, history link and restore, translation, menus with the catalog on and off); `McpProductsTest` the
`products_*` tools; `ProductEditorialTest` history, restoration, proposals, shared previews, translation and the
`content_*` tools; `ShopProductCategoriesTest` categories from the panel and MCP (validation, history, proposals,
restore) and their `product_type` / `custom_label_0` in the Meta feed; `McpSurfaceContractTest` runs the MCP contract with the catalog off and on.
