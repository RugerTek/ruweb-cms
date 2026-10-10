<?php

namespace App\Mcp;

class ToolRegistry
{
    public static function all(): array
    {
        return [
            ...self::pageTools(),
            ...self::postTools(),
            ...self::mediaTools(),
            ...self::settingTools(),
            ...self::menuTools(),
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
                        'page_id' => ['type' => 'integer', 'description' => 'ID de la página.'],
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
                'description' => 'Lista todos los menús de navegación del CMS con su nombre, slug, location y cantidad de items.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
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
                    ],
                ],
            ],
        ];
    }
}
