<?php

use App\Models\ContactSubmission;
use App\Models\Post;

return [
    // Versioned deployment choice. No database setting or environment override.
    'admin_profile' => 'full',

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

    // Collection creation shortcuts in the public admin bar. Pages are always offered separately.
    'collections' => [
        'posts' => [
            'model' => Post::class,
            'label' => 'admin.content_type_post',
            'create_route' => 'admin.posts.create',
        ],
    ],

    'templates' => [
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
            'authorize',
            'token',
            '.well-known/*',
            'storage/*',
            'build/*',
            'up',
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
            'site_name',
            'site_description',
            'site_favicon',
            'admin_login_path',
            'contact_phone',
            'contact_whatsapp',
            'contact_email',
            'contact_address',
            'footer_tagline',
            'footer_copyright',
        ],
    ],
];
