<?php

namespace App\Mcp;

use App\Models\Locale;

class ToolRegistry
{
    public static function all(): array
    {
        return [
            ['name' => 'cms_capabilities', 'description' => 'Discover deployment profile, capabilities, template schemas and editor compatibility. Permissions remain account/scope dependent.', 'inputSchema' => ['type' => 'object', 'properties' => new \stdClass]],
            ...self::phaseThreeTools(),
            ...self::pageTools(),
            ...self::postTools(),
            ...self::mediaTools(),
            ...self::settingTools(),
            ...self::menuTools(),
        ];
    }

    private static function phaseThreeTools(): array
    {
        $entity = ['type' => ['type' => 'string', 'enum' => ['pages', 'posts']], 'id' => ['type' => 'integer', 'minimum' => 1]];
        $revision = ['expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]];
        $tool = fn (string $name, string $description, array $properties = [], array $required = []) => ['name' => $name, 'description' => $description, 'inputSchema' => ['type' => 'object', 'properties' => $properties ?: new \stdClass, 'required' => $required]];

        return [
            $tool('settings_update_batch', 'Admin/full scope: atomically configure allowed layout, contact and SEO settings. One canonical row per fact; localized values only on supported keys. Secrets are excluded.', ['settings' => ['type' => 'object', 'properties' => array_fill_keys(config('cms.mcp.settings_writable_keys', []), new \stdClass), 'additionalProperties' => false], 'locale' => ['type' => 'string', 'enum' => Locale::installedCodes()], 'expected_revisions' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]]], ['settings']),
            $tool('content_read_field', 'Read an editorial string in character ranges or an array in item ranges after a clipped detail response. Check revision between reads.', $entity + ['field' => ['type' => 'string', 'maxLength' => 500], 'offset' => ['type' => 'integer', 'minimum' => 0], 'length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8192]], ['type', 'id', 'field']),
            $tool('cms_schema', 'Discover the exact editorial schema, locale rules and creation example for an installed content type/template.', ['type' => ['type' => 'string', 'enum' => ['pages', 'posts']], 'template_key' => ['type' => 'string', 'enum' => array_keys(config('cms.templates', []))]], ['type']),
            $tool('locales_list', 'List installed languages, activation/publication flags and installable catalog.'),
            $tool('locales_configure', 'Admin only: install a catalog language and/or patch activation/publication flags. Base language stays active/public.', ['install' => ['type' => 'string', 'enum' => Locale::catalogCodes()], 'locales' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => ['is_active' => ['type' => 'boolean'], 'is_public' => ['type' => 'boolean']], 'additionalProperties' => false]]]),
            $tool('content_translate', 'Create a draft translation in the same group, preserving media and remapping associations. Identical retries return the existing translation. Then patch translated copy and slug.', $entity + ['locale' => ['type' => 'string', 'enum' => Locale::installedCodes()]], ['type', 'id', 'locale']),
            $tool('content_translation_changes', 'Inspect source changes in pages of 100 fields. Text ranges default to 256 characters; select field/offset/length for its complete review. Hashes remain intact.', $entity + ['page' => ['type' => 'integer', 'minimum' => 1], 'field' => ['type' => 'string', 'maxLength' => 500], 'offset' => ['type' => 'integer', 'minimum' => 0], 'length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8192]], ['type', 'id']),
            $tool('content_translation_acknowledge', 'Acknowledge a reviewed source field on a draft translation; stale source hash/revision fails without loss.', $entity + $revision + ['field' => ['type' => 'string', 'maxLength' => 500], 'base_hash' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]], ['type', 'id', 'field', 'base_hash']),
            $tool('content_publication', 'Admin/full scope only: schedule future publication or withdraw publication. Future rows stay absent publicly until the publication instant.', $entity + $revision + ['action' => ['type' => 'string', 'enum' => ['schedule', 'unpublish']], 'published_at' => ['type' => 'string', 'format' => 'date-time']], ['type', 'id', 'action']),
            $tool('content_preview', 'Issue an expiring draft preview URL without a browser session. Token URLs are confidential; expiry 1–168 hours.', $entity + ['hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 168]], ['type', 'id']),
            $tool('media_authorize_upload', 'Obtain a short-lived credential-bound multipart upload ticket. Transfer file bytes over HTTP with the same bearer; never place binary data in model context.'),
            $tool('media_usages', 'Inspect paginated media references before deletion, including content JSON, pivots, settings and versions.', ['media_id' => ['type' => 'integer', 'minimum' => 1], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['media_id']),
            $tool('media_delete', 'Delete unused media using the shared protected pipeline; used media is rejected without loss.', ['media_id' => ['type' => 'integer', 'minimum' => 1]], ['media_id']),
        ];
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    private static function pageTools(): array
    {
        return [
            [
                'name' => 'pages_list',
                'description' => 'Lista todas las páginas del CMS con su estado, template y fecha de publicación. Soporta filtros por estado y búsqueda por título.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['draft', 'published'], 'description' => 'Filtrar por estado.'],
                        'fields' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string']],
                        'search' => ['type' => 'string', 'description' => 'Buscar por título.'],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Página de resultados, empezando en 1. Recorrer hasta meta.last_page.'],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Resultados por página (default: 20).'],
                    ],
                ],
            ],
            [
                'name' => 'pages_get',
                'description' => 'Obtiene el detalle completo de una página, incluyendo su content_json y featured image.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['page_id'],
                    'properties' => [
                        'fields' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string']],
                        'page_id' => ['type' => 'integer', 'description' => 'ID de la página.'],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                    ],
                ],
            ],
            [
                'name' => 'pages_create_draft',
                'description' => 'Crea una página nueva en estado draft. El slug debe ser único dentro del idioma.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['title', 'slug', 'template_key'],
                    'properties' => [
                        'locale' => ['type' => 'string', 'description' => 'Código de idioma instalado. Si se omite, usa el idioma base.'],
                        'title' => ['type' => 'string', 'description' => 'Título de la página.'],
                        'slug' => ['type' => 'string', 'description' => 'Slug único (URL-friendly).'],
                        'template_key' => ['type' => 'string', 'enum' => array_keys(config('cms.templates', [])), 'description' => 'Clave del template a usar.'],
                        'operation_key' => ['type' => 'string', 'maxLength' => 100],
                        'content_json' => ['type' => 'object', 'description' => 'Contenido estructurado (meta + sections).'],
                        'featured_image' => ['type' => 'integer', 'description' => 'ID del media a usar como imagen destacada.'],
                    ],
                ],
            ],
            [
                'name' => 'pages_update_draft',
                'description' => 'Edita únicamente una página en borrador; rechaza publicados sin alterarlos. Solo enviar los campos a modificar.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['page_id'],
                    'properties' => [
                        'page_id' => ['type' => 'integer', 'description' => 'ID de la página.'],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                        'title' => ['type' => 'string'],
                        'slug' => ['type' => 'string'],
                        'template_key' => ['type' => 'string', 'enum' => array_keys(config('cms.templates', []))],
                        'content_json' => ['type' => 'object', 'description' => 'Parche: objetos combinan claves, listas se reemplazan completas, null elimina una clave. Omitidos se conservan; parche raíz vacío no cambia contenido.'],
                        'featured_image' => ['type' => ['integer', 'null'], 'description' => 'Pasar null para quitar la imagen.'],
                    ],
                ],
            ],
            [
                'name' => 'pages_publish',
                'description' => 'Publica una página (cambia su estado a published). Si ya fue publicada antes, conserva la fecha original.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['page_id'],
                    'properties' => [
                        'page_id' => ['type' => 'integer', 'description' => 'ID de la página.'],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Posts
    // -------------------------------------------------------------------------

    private static function postTools(): array
    {
        return [
            [
                'name' => 'posts_list',
                'description' => 'Lista todos los posts del blog con su estado, excerpt y fecha de publicación. Soporta filtros por estado y búsqueda.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['draft', 'published'], 'description' => 'Filtrar por estado.'],
                        'fields' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string']],
                        'search' => ['type' => 'string', 'description' => 'Buscar por título o slug.'],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Página de resultados, empezando en 1. Recorrer hasta meta.last_page.'],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    ],
                ],
            ],
            [
                'name' => 'posts_get',
                'description' => 'Obtiene el detalle completo de un post, incluyendo su contenido, meta_json y featured image.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['post_id'],
                    'properties' => [
                        'fields' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string']],
                        'post_id' => ['type' => 'integer', 'description' => 'ID del post.'],
                    ],
                ],
            ],
            [
                'name' => 'posts_create_draft',
                'description' => 'Crea un post nuevo en estado draft. El slug debe ser único dentro del idioma y solo puede contener letras minúsculas, números y guiones.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['title', 'slug'],
                    'properties' => [
                        'locale' => ['type' => 'string', 'description' => 'Código de idioma instalado. Si se omite, usa el idioma base.'],
                        'title' => ['type' => 'string'],
                        'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
                        'operation_key' => ['type' => 'string', 'maxLength' => 100],
                        'excerpt' => ['type' => 'string', 'maxLength' => 500],
                        'content' => ['type' => 'string', 'description' => 'Contenido HTML o markdown del post.'],
                        'featured_image' => ['type' => 'integer'],
                        'meta_json' => ['type' => 'object', 'description' => 'SEO: title, description.'],
                    ],
                ],
            ],
            [
                'name' => 'posts_update_draft',
                'description' => 'Edita únicamente un post en borrador; rechaza publicados sin alterarlos. Solo enviar los campos a modificar.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['post_id'],
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                        'categories' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'title' => ['type' => 'string'],
                        'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
                        'excerpt' => ['type' => ['string', 'null']],
                        'content' => ['type' => ['string', 'null']],
                        'featured_image' => ['type' => ['integer', 'null']],
                        'meta_json' => ['type' => ['object', 'null'], 'description' => 'Parche de claves SEO; null en una clave la elimina. null en meta_json limpia todo el SEO.'],
                    ],
                ],
            ],
            [
                'name' => 'posts_publish',
                'description' => 'Publica un post (cambia su estado a published).',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['post_id'],
                    'properties' => [
                        'post_id' => ['type' => 'integer'],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                        'categories' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Media
    // -------------------------------------------------------------------------

    private static function mediaTools(): array
    {
        return [
            [
                'name' => 'media_list',
                'description' => 'Lista los archivos de la biblioteca de medios. Soporta filtros por tipo (image, application) y búsqueda por nombre o alt.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'locale' => ['type' => 'string', 'description' => 'Idioma activo del medio; omitir para usar el texto base. Una traducción vacía hereda el texto base.'],
                        'type' => ['type' => 'string', 'enum' => ['image', 'application'], 'description' => 'Filtrar por tipo MIME.'],
                        'fields' => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string']],
                        'search' => ['type' => 'string', 'description' => 'Buscar por nombre, título o texto alt.'],
                        'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Página de resultados, empezando en 1. Recorrer hasta meta.last_page.'],
                        'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    ],
                ],
            ],
            [
                'name' => 'media_get',
                'description' => 'Obtiene el detalle de un archivo de medios: URL, dimensiones, alt, título y metadatos.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['media_id'],
                    'properties' => [
                        'locale' => ['type' => 'string', 'description' => 'Idioma activo del medio; omitir para usar el texto base. Una traducción vacía hereda el texto base.'],
                        'media_id' => ['type' => 'integer', 'description' => 'ID del archivo de medios.'],
                    ],
                ],
            ],
            [
                'name' => 'media_update_metadata',
                'description' => 'Actualiza el texto alt y/o título de un archivo de medios. Útil para mejorar accesibilidad y SEO.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['media_id'],
                    'properties' => [
                        'locale' => ['type' => 'string', 'description' => 'Idioma activo del medio; omitir para usar el texto base. Una traducción vacía hereda el texto base.'],
                        'media_id' => ['type' => 'integer'],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                        'alt' => ['type' => ['string', 'null'], 'description' => 'Texto alternativo para accesibilidad.'],
                        'title' => ['type' => ['string', 'null'], 'description' => 'Título descriptivo del archivo.'],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Settings
    // -------------------------------------------------------------------------

    private static function settingTools(): array
    {
        return [
            [
                'name' => 'settings_list',
                'description' => 'Devuelve todos los settings del CMS agrupados por categoría.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'settings_get',
                'description' => 'Obtiene el valor actual de un setting específico por su clave.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['key'],
                    'properties' => [
                        'key' => ['type' => 'string', 'description' => 'Clave del setting (ej: site.name).'],
                    ],
                ],
            ],
            [
                'name' => 'settings_update',
                'description' => 'Actualiza el valor de un setting. Solo disponible para admins. Solo se pueden actualizar claves incluidas en la allowlist de cms.mcp.settings_writable_keys.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['key', 'value'],
                    'properties' => [
                        'key' => ['type' => 'string', 'description' => 'Clave del setting a actualizar.'],
                        'locale' => ['type' => 'string', 'enum' => Locale::installedCodes()],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                        'value' => ['description' => 'Nuevo valor (puede ser string, número, booleano o null).'],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Menus
    // -------------------------------------------------------------------------

    private static function menuTools(): array
    {
        return [
            [
                'name' => 'menus_list',
                'description' => 'Lista menús paginados con nombre, idioma, location y cantidad de items.',
                'inputSchema' => ['type' => 'object', 'properties' => ['page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]]],
            ],
            [
                'name' => 'menus_get',
                'description' => 'Obtiene un menú completo con su árbol de items anidados, URLs resueltas y orden.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['menu_id'],
                    'properties' => [
                        'menu_id' => ['type' => 'integer', 'description' => 'ID del menú.'],
                    ],
                ],
            ],
            [
                'name' => 'menus_create',
                'description' => 'Crea un nuevo menú de navegación. Solo disponible para admins.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['name', 'slug'],
                    'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Nombre del menú.'],
                        'locale' => ['type' => 'string', 'enum' => Locale::installedCodes()],
                        'operation_key' => ['type' => 'string', 'maxLength' => 100],
                        'slug' => ['type' => 'string', 'description' => 'Slug único para identificar el menú.'],
                        'location' => ['type' => 'string', 'description' => 'Ubicación en el tema (ej: header, footer).'],
                    ],
                ],
            ],
            [
                'name' => 'menus_update',
                'description' => 'Actualiza el nombre, slug o location de un menú existente.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['menu_id'],
                    'properties' => [
                        'menu_id' => ['type' => 'integer'],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                        'name' => ['type' => 'string'],
                        'slug' => ['type' => 'string'],
                        'location' => ['type' => ['string', 'null']],
                    ],
                ],
            ],
            [
                'name' => 'menus_sync_items',
                'description' => 'Reemplaza todos los items de un menú de forma atómica. Enviar un array vacío limpia el menú. Los items pueden anidarse usando parent_id con el id temporal del item padre.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['menu_id', 'items'],
                    'properties' => [
                        'menu_id' => ['type' => 'integer'],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                        'items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'required' => ['label', 'type', 'target', 'order'],
                                'properties' => [
                                    'id' => ['type' => 'integer', 'description' => 'ID temporal del cliente para resolver parent_id.'],
                                    'parent_id' => ['type' => 'integer', 'description' => 'ID temporal del item padre (referencia a id del mismo request).'],
                                    'label' => ['type' => 'string'],
                                    'type' => ['type' => 'string', 'enum' => ['custom_link', 'page', 'post', 'taxonomy']],
                                    'linkable_id' => ['type' => 'integer', 'description' => 'ID del recurso enlazado (para tipos page, post, taxonomy).'],
                                    'url' => ['type' => 'string', 'description' => 'URL para tipo custom_link.'],
                                    'target' => ['type' => 'string', 'enum' => ['_self', '_blank']],
                                    'order' => ['type' => 'integer', 'minimum' => 0],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'menus_delete',
                'description' => 'Elimina un menú y todos sus items. Solo disponible para admins.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['menu_id'],
                    'properties' => [
                        'menu_id' => ['type' => 'integer'],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                    ],
                ],
            ],
        ];
    }
}
