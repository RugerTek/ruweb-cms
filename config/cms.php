<?php

use App\Connectors\Bancard\BancardConnector;
use App\Connectors\Meta\MetaCatalogConnector;
use App\Connectors\MetaPixel\MetaPixelConnector;
use App\Connectors\Sources\ShopProductSource;
use App\Models\ContactSubmission;
use App\Models\Post;
use App\Models\Product;
use App\Shop\Payments\Bancard\BancardProvider;
use App\Shop\Payments\ManualPaymentProvider;

return [
    // Versioned deployment choice. No database setting or environment override.
    'admin_profile' => 'full',

    // Optional shop module. The administrator turns it on and off from the panel (Settings > Shop, the `shop_mode`
    // setting); `mode` here is the versioned LIMIT a deployment allows, not the switch: off (the site cannot turn
    // the shop on) | catalog (up to the catalog) | checkout (no limit). No environment override. Read the effective
    // mode only through App\Support\ShopModule (docs/shop/catalog.md).
    'shop' => [
        'mode' => 'checkout',
        // ISO 4217; one currency per site. Amounts are stored as integers (Guaraníes have no decimals).
        'currency' => 'PYG',
        // Public catalog prefix: /productos and /en/productos. Reserved for pages only while the module is on.
        'path' => 'productos',
        // Merged into taxonomy_types while the catalog is on.
        'taxonomy_types' => ['brand'],
        // Merged into collections.products while the catalog is on (App\Providers\ShopServiceProvider).
        'collection' => [
            'model' => Product::class,
            'label' => 'shop.content_type_product',
            'create_route' => 'admin.products.create',
            'custom_code_public' => false,
            'mcp_operations' => 'products',
            // History, versions, proposals, shared previews, translation and content_* tools (App\Support\EditorialTypes).
            'editorial_history' => true,
        ],
        // Merged into custom_code.indexes.shop while the catalog is on: code scoped to the public catalog listing.
        'custom_code_index' => ['label' => 'Tienda', 'routes' => ['shop.index', 'localized.shop.index']],
        // Merged into content_schemas.product while the catalog is on. Commerce fields (sku, prices, stock,
        // variants) live on the base-language row only, so they are not translation paths.
        'content_schema' => [
            'title' => ['type' => 'text', 'translatable' => true],
            'excerpt' => ['type' => 'textarea', 'translatable' => true],
            'description' => ['type' => 'richtext', 'translatable' => true],
            'meta_json.title' => ['type' => 'text', 'translatable' => true],
            'meta_json.description' => ['type' => 'textarea', 'translatable' => true],
            'featured_image' => ['type' => 'media', 'localized_media' => true],
            'gallery' => ['type' => 'media', 'localized_media' => true],
            'taxonomies' => ['type' => 'internal_reference', 'remap' => true],
        ],
        // Cart, checkout and orders (PLAN.md, stage 3). Active only while the effective mode is `checkout`.
        'checkout' => [
            // Days a guest cart (cookie `ruweb_cart`) lives without activity.
            'cart_days' => 30,
            // Orders with an automatic payment method still unpaid after this many hours are cancelled.
            'unpaid_order_hours' => 48,
            // Bancard attempts still pending after this many minutes are queried and, if unpaid, rolled back.
            'bancard_pending_minutes' => 10,
            // Order numbers: ORD-2026-000001.
            'order_prefix' => 'ORD',
        ],
        // key => class implementing App\Contracts\Shop\PaymentProvider (App\Shop\Payments\PaymentProviderRegistry).
        // The key is the `provider` column of payment_methods and payments.
        'payment_providers' => [
            'manual' => ManualPaymentProvider::class,
            'bancard' => BancardProvider::class,
        ],
        // Bancard vPOS 2.0 (PLAN.md E3.1). Credentials are per site, encrypted in payment_methods, never here.
        'bancard' => [
            'urls' => [
                'staging' => 'https://vpos.infonet.com.py:8888',
                'production' => 'https://vpos.infonet.com.py',
            ],
            // Local fake server for development and tests; the Bancard client honours it only when
            // app()->environment('local', 'testing'), so it can never redirect a real site's payments.
            'staging_url_override' => env('BANCARD_STAGING_URL'),
            // Version of {env}/checkout/javascript/dist/bancard-checkout-<version>.js the order page loads.
            'checkout_js_version' => '5.0.1',
        ],
    ],

    // Admin > Connectors (PLAN.md §4): key => class implementing App\Contracts\Connectors\Connector. Adding one is a
    // class, an entry here, lang/{es,en}/connectors.php keys and tests; every connector shares the `connectors` table.
    'connectors' => [
        'meta' => MetaCatalogConnector::class,
        'bancard' => BancardConnector::class,
        'meta_pixel' => MetaPixelConnector::class,
    ],

    // Meta Pixel + Conversions API (App\Connectors\MetaPixel, docs/operations/meta-pixel.md). The pixel ID, the
    // access token (encrypted in the connectors table) and the test events code are per site, never here.
    'meta_pixel' => [
        // Graph API version of the Conversions API endpoint. v27.0 is the latest one (Meta changelog, released
        // 2026-11-04, checked 2026-11-24); every version is supported for the Conversions API for at least two years.
        'graph_api_version' => 'v27.0',
        'graph_url' => 'https://graph.facebook.com',
        // Seconds to wait for Meta in a server event or the panel's test event.
        'timeout' => 10,
        // ISO 3166-1 alpha-2 of the customers' country (`country` in user_data) and the calling code added to phone
        // numbers written without one (0981 123456 → 595981123456), as Meta asks.
        'country' => 'py',
        'phone_country_code' => '595',
    ],

    // Meta v2 «Conectar con Facebook» (plan-meta-v2): Graph API calls of the catalog connector with the system user token
    // delivered by the RugerTek Connect broker. The token and the broker credentials are per site, encrypted in the
    // connectors table (App\Connectors\Meta\Connect\MetaConnectionStore), never here.
    'meta' => [
        // Graph API version of every catalog call (v27.0, the version the spike verified on 2026-11-24).
        'graph_version' => 'v27.0',
        'graph_url' => 'https://graph.facebook.com',
        // Seconds to wait for Meta; calls are never retried blindly.
        'timeout' => 20,
        'connection_timeout' => 10,
        // RugerTek Connect broker (one redirect URI and the app secret live there). Empty: the panel offers only the
        // manual feed (v1).
        'connect_url' => env('RUWEB_CONNECT_URL'),
        // http:// broker URL for development; honoured only in the local and testing environments.
        'connect_allow_http' => (bool) env('RUWEB_CONNECT_ALLOW_HTTP', false),
        // Seconds to wait for the broker.
        'connect_timeout' => 10,
    ],

    // Where feed connectors read items from: key => class implementing App\Contracts\Catalog\CatalogSource.
    'catalog_sources' => [
        'products' => ShopProductSource::class,
    ],

    // Application version. Single source of truth is the VERSION file at the
    // repository root, bumped together with CHANGELOG.md (see skills/changelog-version).
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: '0.0.0',

    'snapshots' => [
        // Base directory for everything the snapshot module writes: named
        // archives, pending uploads, work folders and pre-restore backups.
        // Tests point this at an isolated temp directory. See docs/operations/snapshots.md.
        'root' => env('CMS_SNAPSHOTS_ROOT') ?: storage_path('app/private'),
        // Put the site in maintenance mode while a restore runs. Tests turn
        // this off because `artisan down` writes to the real storage/framework.
        'maintenance_mode' => env('CMS_SNAPSHOTS_MAINTENANCE_MODE', true),
    ],

    'upload' => [
        'allowed_image_mimes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'],
        'allowed_document_mimes' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'pdf', 'doc', 'docx'],
        'image_max_size' => env('CMS_IMAGE_MAX_SIZE', 5120), // KB
        'document_max_size' => env('CMS_DOCUMENT_MAX_SIZE', 10240), // KB
    ],

    'image_optimization' => [
        'enabled' => env('CMS_IMAGE_OPTIMIZATION_ENABLED', true),
        'max_width' => env('CMS_IMAGE_OPTIMIZATION_MAX_WIDTH', 2048),
        'quality' => env('CMS_IMAGE_OPTIMIZATION_QUALITY', 80),
        'keep_original' => env('CMS_IMAGE_OPTIMIZATION_KEEP_ORIGINAL', false),
    ],

    'rich_text' => [
        // Hosts whose iframes survive sanitisation, for video and map embeds.
        'allowed_embed_hosts' => [
            'youtube.com',
            'youtube-nocookie.com',
            'youtu.be',
            'vimeo.com',
            'player.vimeo.com',
            'google.com',
        ],
    ],

    'responsive_images' => [
        'enabled' => env('CMS_RESPONSIVE_IMAGES_ENABLED', true),
        'widths' => [240, 480, 768, 1024, 1536],
    ],

    'roles' => ['admin', 'editor'],

    'statuses' => ['draft', 'published'],

    // Taxonomy types the panel and MCP manage; add a type here before using it anywhere else.
    'taxonomy_types' => ['category'],

    // Collection creation shortcuts in the public admin bar. Pages are always offered separately.
    'collections' => [
        'posts' => [
            'model' => Post::class,
            'label' => 'admin.content_type_post',
            'create_route' => 'admin.posts.create',
            'custom_code_public' => true,
            // Tool prefix agents use (posts_list, posts_update…). Required: see docs/operations/mcp-surface.md.
            'mcp_operations' => 'posts',
        ],
    ],

    // Operational kill switch; CLI marker also works with cached config and without the DB.
    'custom_code' => [
        'enabled' => env('CMS_CUSTOM_CODE_ENABLED', true),
        'indexes' => [
            'blog' => ['label' => 'Blog', 'routes' => ['blog.index', 'localized.blog.index']],
        ],
    ],

    'composition' => [
        'components' => [
            'callout' => [
                'type' => 'structured',
                'translatable_paths' => ['heading', 'body'],
                'view' => 'components.composition.callout',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:500'],
                    'body' => ['nullable', 'string', 'max:10000'],
                    'image_id' => ['nullable', 'integer', 'exists:media,id'],
                ],
            ],
            'accordion' => [
                'type' => 'interactive',
                'translatable_paths' => ['heading', 'items.*.question', 'items.*.answer'],
                'view' => 'components.composition.accordion',
                'rules' => [
                    'heading' => ['nullable', 'string', 'max:500'],
                    'items' => ['required', 'array', 'min:1', 'max:50'],
                    'items.*' => ['array:question,answer'],
                    'items.*.question' => ['required', 'string', 'max:500'],
                    'items.*.answer' => ['required', 'string', 'max:10000'],
                    'initially_open' => ['nullable', 'integer', 'min:0', 'max:49'],
                ],
            ],
        ],
    ],

    'templates' => [
        'html' => [
            'name' => 'HTML page',
            'visual_editor' => false,
            'sections' => ['content'],
            'schema' => [
                'meta.title' => ['type' => 'text', 'translatable' => true],
                'meta.description' => ['type' => 'textarea', 'translatable' => true],
                'meta.featured_image' => ['type' => 'media', 'localized_media' => true],
                'sections.content.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.content.html' => ['type' => 'composition_html', 'translatable' => true],
            ],
        ],
        'empty' => [
            'name' => 'HTML canvas',
            'visual_editor' => false,
            'sections' => ['content'],
            'schema' => [
                'meta.title' => ['type' => 'text', 'translatable' => true],
                'meta.description' => ['type' => 'textarea', 'translatable' => true],
                'meta.featured_image' => ['type' => 'media', 'localized_media' => true],
                'sections.content.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.content.html' => ['type' => 'composition_html', 'translatable' => true],
            ],
        ],
        'composed' => [
            'name' => 'Composed page',
            'visual_editor' => false,
            'sections' => ['composition'],
            'schema' => [
                'meta.title' => ['type' => 'text', 'translatable' => true],
                'meta.description' => ['type' => 'textarea', 'translatable' => true],
                'meta.featured_image' => ['type' => 'media', 'localized_media' => true],
                'sections.composition.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.composition.blocks' => ['type' => 'composition_blocks', 'translatable' => true],
            ],
        ],
        'default' => [
            'name' => 'Default Page',
            'sections' => ['content'],
            'schema' => [
                'meta.title' => ['type' => 'text', 'translatable' => true],
                'meta.description' => ['type' => 'textarea', 'translatable' => true],
                'meta.featured_image' => ['type' => 'media', 'localized_media' => true],
                'sections.content.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.content.heading' => ['type' => 'text', 'translatable' => true],
                'sections.content.body' => ['type' => 'textarea', 'translatable' => true, 'rich_text' => 'section-body'],
            ],
        ],
        'home' => [
            'name' => 'Homepage',
            'sections' => ['hero', 'features', 'cta'],
            'schema' => [
                'meta.title' => ['type' => 'text', 'translatable' => true],
                'meta.description' => ['type' => 'textarea', 'translatable' => true],
                'meta.featured_image' => ['type' => 'media', 'localized_media' => true],
                'sections.hero.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.hero.heading' => ['type' => 'text', 'translatable' => true],
                'sections.hero.body' => ['type' => 'textarea', 'translatable' => true, 'rich_text' => 'short-description'],
                'sections.hero.image_id' => ['type' => 'media', 'localized_media' => true],
                'sections.features.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.features.heading' => ['type' => 'text', 'translatable' => true],
                'sections.features.body' => ['type' => 'textarea', 'translatable' => true, 'rich_text' => 'section-body'],
                'sections.features.logos.*.image_id' => ['type' => 'media', 'localized_media' => true],
                'sections.features.logos.*.label' => ['type' => 'text', 'translatable' => true],
                'sections.cta.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.cta.heading' => ['type' => 'text', 'translatable' => true],
                'sections.cta.body' => ['type' => 'textarea', 'translatable' => true, 'rich_text' => 'short-description'],
                'sections.cta.button_label' => ['type' => 'text', 'translatable' => true],
                'sections.cta.button_page' => ['type' => 'page_reference', 'preserve' => true],
                'sections.cta.button_url' => ['type' => 'url', 'preserve' => true],
                'sections.cta.button_new_tab' => ['type' => 'boolean', 'preserve' => true],
            ],
        ],
        'home-alt' => [
            'name' => 'Homepage Alt',
            'sections' => ['hero', 'features', 'cta'],
            'schema' => [
                'meta.title' => ['type' => 'text', 'translatable' => true],
                'meta.description' => ['type' => 'textarea', 'translatable' => true],
                'meta.featured_image' => ['type' => 'media', 'localized_media' => true],
                'sections.hero.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.hero.heading' => ['type' => 'text', 'translatable' => true],
                'sections.hero.body' => ['type' => 'textarea', 'translatable' => true, 'rich_text' => 'short-description'],
                'sections.hero.image_id' => ['type' => 'media', 'localized_media' => true],
                'sections.features.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.features.heading' => ['type' => 'text', 'translatable' => true],
                'sections.features.body' => ['type' => 'textarea', 'translatable' => true, 'rich_text' => 'section-body'],
                'sections.cta.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.cta.heading' => ['type' => 'text', 'translatable' => true],
                'sections.cta.body' => ['type' => 'textarea', 'translatable' => true, 'rich_text' => 'short-description'],
                'sections.cta.button_label' => ['type' => 'text', 'translatable' => true],
                'sections.cta.button_page' => ['type' => 'page_reference', 'preserve' => true],
                'sections.cta.button_url' => ['type' => 'url', 'preserve' => true],
                'sections.cta.button_new_tab' => ['type' => 'boolean', 'preserve' => true],
            ],
        ],
        'contact' => [
            'name' => 'Contact',
            'sections' => ['intro', 'form'],
            'schema' => [
                'meta.title' => ['type' => 'text', 'translatable' => true],
                'meta.description' => ['type' => 'textarea', 'translatable' => true],
                'meta.featured_image' => ['type' => 'media', 'localized_media' => true],
                'sections.intro.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.intro.heading' => ['type' => 'text', 'translatable' => true],
                'sections.intro.body' => ['type' => 'textarea', 'translatable' => true, 'rich_text' => 'short-description'],
                'sections.form.is_visible' => ['type' => 'boolean', 'preserve' => true],
                'sections.form.heading' => ['type' => 'text', 'translatable' => true],
                'sections.form.labels.name' => ['type' => 'text', 'translatable' => true],
                'sections.form.labels.email' => ['type' => 'text', 'translatable' => true],
                'sections.form.labels.phone' => ['type' => 'text', 'translatable' => true],
                'sections.form.labels.subject' => ['type' => 'text', 'translatable' => true],
                'sections.form.labels.message' => ['type' => 'text', 'translatable' => true],
                'sections.form.button_label' => ['type' => 'text', 'translatable' => true],
                'sections.form.success_message' => ['type' => 'textarea', 'translatable' => true, 'rich_text' => 'short-description'],
                'sections.form.subject_options.*.label' => ['type' => 'text', 'translatable' => true],
                'sections.form.notify_enabled' => ['type' => 'boolean', 'preserve' => true],
                'sections.form.notify_email' => ['type' => 'text', 'preserve' => true],
            ],
        ],
    ],

    // Public forms that store submissions. One entry per form; the admin
    // renders a tab per key under Submissions. Forms are code-owned, see docs/content/forms.md.
    'forms' => [
        'contact' => [
            'model' => ContactSubmission::class,
            'searchable' => ['name', 'email', 'phone', 'subject', 'message'],
            'recaptcha_action' => 'contact',
            'template' => 'contact',
            'section' => 'form',
        ],
    ],

    'content_schemas' => [
        'post' => [
            'title' => ['type' => 'text', 'translatable' => true],
            'excerpt' => ['type' => 'textarea', 'translatable' => true],
            'content' => ['type' => 'richtext', 'translatable' => true],
            'meta_json.title' => ['type' => 'text', 'translatable' => true],
            'meta_json.description' => ['type' => 'textarea', 'translatable' => true],
            'featured_image' => ['type' => 'media', 'localized_media' => true],
            'taxonomies' => ['type' => 'internal_reference', 'remap' => true],
        ],
        'taxonomy' => [
            'name' => ['type' => 'text', 'translatable' => true],
            'description' => ['type' => 'textarea', 'translatable' => true],
            'parent_id' => ['type' => 'internal_reference', 'remap' => true],
            'type' => ['type' => 'text', 'preserve' => true],
            'order' => ['type' => 'number', 'preserve' => true],
        ],
    ],

    'locales' => [
        // Catalog of languages that may be installed from Settings > Languages.
        // The active set of languages lives in the `locales` table, not here.
        'catalog' => [
            'es' => 'Español',
            'en' => 'English',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'it' => 'Italiano',
            'pt' => 'Português',
            'nl' => 'Nederlands',
            'ca' => 'Català',
            'gl' => 'Galego',
            'eu' => 'Euskara',
            'sv' => 'Svenska',
            'no' => 'Norsk',
            'da' => 'Dansk',
            'fi' => 'Suomi',
            'pl' => 'Polski',
            'cs' => 'Čeština',
            'sk' => 'Slovenčina',
            'hu' => 'Magyar',
            'ro' => 'Română',
            'el' => 'Ελληνικά',
            'ru' => 'Русский',
            'uk' => 'Українська',
            'tr' => 'Türkçe',
            'ar' => 'العربية',
            'he' => 'עברית',
            'ja' => '日本語',
            'ko' => '한국어',
            'zh' => '中文',
            'hi' => 'हिन्दी',
            'th' => 'ไทย',
            'vi' => 'Tiếng Việt',
            'id' => 'Bahasa Indonesia',
        ],
        'default' => env('CMS_BASE_LOCALE', 'es'),
    ],

    'default_admin' => [
        'name' => env('CMS_ADMIN_NAME', 'Admin'),
        'username' => env('CMS_ADMIN_USERNAME', 'admin'),
        'email' => env('CMS_ADMIN_EMAIL', 'admin@ruweb.local'),
        'password' => env('CMS_ADMIN_PASSWORD', 'password'),
    ],

    // Map picker used by `map_point` settings (docs/admin/layout.md). Free OSM tiles;
    // switch the URL and attribution here to use another provider.
    'maps' => [
        'tiles_url' => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        'default_center' => [-25.2867, -57.6470],
        'default_zoom' => 12,
        'pinned_zoom' => 16,
    ],

    // 301/302 rules and the 404 monitor behind them (docs/operations/redirects.md).
    'redirects' => [
        'status_codes' => [301, 302],
        'monitor_not_found' => env('CMS_MONITOR_NOT_FOUND', true),
        // Paths never redirected and never counted as a 404: the panel, machine endpoints and assets.
        'ignore_paths' => [
            'admin',
            'admin/*',
            'mcp',
            'mcp/*',
            'storage/*',
            'build/*',
            'up',
            // Public connector feeds: requests with a wrong token must not fill the 404 monitor.
            'feeds/*',
            // Payment provider notifications (Bancard posts here, including an empty monitoring ping every 5 minutes).
            'webhooks/*',
            // Discovery probes (MCP clients look for OAuth, which this starter does not offer) on every connection.
            '.well-known/*',
        ],
        'per_page' => 25,
    ],

    'menu_locations' => [
        'header' => 'Header Navigation',
        'footer' => 'Footer Navigation',
    ],

    'mcp' => [
        'per_page' => 20,
        'settings_writable_keys' => [
            'homepage_translation_group_id',
            'site_name',
            'site_description',
            'site_favicon',
            'site_logo',
            'default_social_image',
            'header_cta_enabled',
            'header_cta_label',
            'header_cta_page',
            'header_cta_url',
            'header_cta_new_tab',
            'contact_location',
            'contact_social_links',
            'footer_credit_enabled',
            'footer_credit',
            'admin_login_path',
            'contact_phone',
            'contact_whatsapp',
            'contact_email',
            'contact_address',
            'footer_tagline',
            'footer_copyright',
            // Shop module switch: validated against the config limit by App\Services\Shop\ShopModuleOperations.
            'shop_mode',
            // Where order notices go (empty = contact_email); guarded by CheckoutReadiness while selling online.
            'shop_orders_email',
        ],
    ],
];
