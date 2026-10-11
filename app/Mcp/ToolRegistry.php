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
            ...self::editorialTools(),
            ...self::submissionTools(),
            ...self::customCodeTools(),
            ...self::pageTools(),
            ...self::postTools(),
            ...self::mediaTools(),
            ...self::settingTools(),
            ...self::menuTools(),
            ...self::taxonomyTools(),
            ...self::redirectTools(),
        ];
    }

    private static function redirectTools(): array
    {
        $paging = ['page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]];
        $revision = ['expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]];
        $id = ['redirect_id' => ['type' => 'integer', 'minimum' => 1]];
        $fields = ['source_path' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Root-relative old path, e.g. /old-page.'],
            'target_url' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Root-relative path on this site or an http(s) URL.'],
            'status_code' => ['type' => 'integer', 'enum' => config('cms.redirects.status_codes', [301, 302])], 'is_active' => ['type' => 'boolean']];
        $tool = fn ($name, $description, $properties, $required = []) => ['name' => $name, 'description' => $description.' Administrator and full credential scope for writes.', 'inputSchema' => ['type' => 'object', 'properties' => $properties ?: new \stdClass, 'required' => $required]];

        return [
            $tool('redirects_list', 'List redirect rules with revision, hit count and last use, in bounded pages.', $paging + ['search' => ['type' => 'string', 'maxLength' => 255]]),
            $tool('redirects_create', 'Create a redirect rule, or replace the rule for the same source path. Self-redirects, loops, the homepage as source and non-http(s) targets are rejected.', $fields, ['source_path', 'target_url', 'status_code']),
            $tool('redirects_update', 'Patch a redirect rule; omitted fields are kept. Requires the current revision.', $id + $revision + $fields, ['redirect_id', 'expected_revision']),
            $tool('redirects_delete', 'Permanently delete a redirect rule. Requires the current revision.', $id + $revision, ['redirect_id', 'expected_revision']),
            $tool('not_found_list', 'List recorded 404 paths, most recent first, to decide which redirects to create.', $paging),
            $tool('not_found_forget', 'Forget one recorded 404 (hit_id) or the whole monitor (all: true). Returns how many were removed.', ['hit_id' => ['type' => 'integer', 'minimum' => 1], 'all' => ['type' => 'boolean', 'const' => true]]),
        ];
    }

    private static function taxonomyTools(): array
    {
        $id = ['taxonomy_id' => ['type' => 'integer', 'minimum' => 1]];
        $revision = ['expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]];
        $fields = ['name' => ['type' => 'string', 'maxLength' => 255], 'slug' => ['type' => 'string', 'maxLength' => 255, 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
            'description' => ['type' => ['string', 'null'], 'maxLength' => 1000], 'parent_id' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'A term of the same type and locale.'],
            'order' => ['type' => ['integer', 'null'], 'minimum' => 0]];
        $type = ['type' => ['type' => 'string', 'enum' => config('cms.taxonomy_types', [])]];
        $tool = fn ($name, $description, $properties, $required = []) => ['name' => 'taxonomies_'.$name, 'description' => $description, 'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required]];

        return [
            $tool('list', 'List taxonomy terms (e.g. blog categories) of one type with revision, translation state and usage count, in bounded pages.', $type + ['locale' => ['type' => 'string', 'enum' => Locale::installedCodes()], 'search' => ['type' => 'string', 'maxLength' => 255], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['type']),
            $tool('get', 'Read one taxonomy term with its revision.', $id, ['taxonomy_id']),
            $tool('create', 'Create a term; it is live immediately (terms have no draft state). operation_key makes exact retries safe. Full credential scope.', $type + $fields + ['locale' => ['type' => 'string', 'enum' => Locale::installedCodes()], 'operation_key' => ['type' => 'string', 'maxLength' => 100]], ['type', 'name', 'slug']),
            $tool('update', 'Patch a term; omitted fields are kept. Full credential scope; requires the current revision.', $id + $revision + $fields, ['taxonomy_id', 'expected_revision']),
            $tool('translate', 'Create (or return the existing) translation of a term in another locale, marked needs_review; then patch its name and slug. Full credential scope.', $id + ['locale' => ['type' => 'string', 'enum' => Locale::installedCodes()]], ['taxonomy_id', 'locale']),
            $tool('delete', 'Permanently delete a term. Child terms move up one level; it is removed from the content and menus that used it, reported in warnings. Administrator and full credential scope; requires the current revision.', $id + $revision, ['taxonomy_id', 'expected_revision']),
        ];
    }

    private static function customCodeTools(): array
    {
        $id = ['id' => ['type' => 'integer', 'minimum' => 1]];
        $revision = ['expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64], 'operation_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]];
        $ref = ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => [...array_keys(app(\App\Support\CustomCodeDestinations::class)->models()), 'indexes']], 'id' => ['type' => ['integer', 'string']], 'locale' => ['type' => 'string'], 'group' => ['type' => 'boolean']], 'required' => ['type', 'id', 'locale'], 'additionalProperties' => false];
        $tool = fn ($name, $description, $properties = [], $required = []) => ['name' => 'custom_code_'.$name, 'description' => $description.' Requires the explicit custom-code credential scope and account custom-code permission in either profile.', 'inputSchema' => ['type' => 'object', 'properties' => $properties ?: new \stdClass, 'required' => $required]];

        return [
            $tool('list', 'List executable snippets and their active versions; reading never executes code.', ['page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]]),
            $tool('get', 'Read a snippet with immutable versions, destination rules, revisions and authored audit events. History is paginated; version_id reads an exact historical payload.', $id + ['versions_page' => ['type' => 'integer', 'minimum' => 1], 'version_id' => ['type' => 'integer', 'minimum' => 1]], ['id']),
            $tool('destinations', 'Discover bounded canonical destination references. Save these references, never site URLs or arbitrary wildcard paths.', ['type' => $ref['properties']['type'], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]]),
            $tool('save', 'Create a snippet or save an immutable pending version. Saving does not activate it. Updates require expected_revision; operation_key makes exact retries safe.', $id + $revision + ['name' => ['type' => 'string', 'maxLength' => 255], 'description' => ['type' => ['string', 'null'], 'maxLength' => 2000], 'type' => ['type' => 'string', 'enum' => ['html', 'css', 'javascript']], 'code' => ['type' => 'string', 'maxLength' => 65536], 'position' => ['type' => 'string', 'enum' => ['head_end', 'body_start', 'body_end']], 'sort_order' => ['type' => 'integer', 'minimum' => -10000, 'maximum' => 10000], 'rules' => ['type' => 'object', 'properties' => ['global' => ['type' => 'boolean'], 'include' => ['type' => 'array', 'maxItems' => 100, 'items' => $ref], 'exclude' => ['type' => 'array', 'maxItems' => 100, 'items' => $ref]], 'required' => ['global', 'include', 'exclude'], 'additionalProperties' => false]], ['name', 'type', 'code', 'position', 'sort_order', 'rules', 'operation_key']),
            $tool('activate', 'Activate exactly version_id owned by this snippet. Exclusions prevail; unrelated newer pending versions remain pending.', $id + $revision + ['version_id' => ['type' => 'integer', 'minimum' => 1]], ['id', 'version_id', 'expected_revision', 'operation_key']),
            $tool('deactivate', 'Stop serving the snippet while retaining its versions and audit history.', $id + $revision, ['id', 'expected_revision', 'operation_key']),
            $tool('evaluate', 'Diagnose target matching and duplicate integrations without executing code. version_id evaluates one immutable proposal; omit it to inspect active snippets.', ['target' => $ref, 'version_id' => ['type' => 'integer', 'minimum' => 1], 'page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], ['target']),
        ];
    }

    private static function submissionTools(): array
    {
        $form = ['form' => ['type' => 'string', 'enum' => array_keys(config('cms.forms', []))]];
        $entity = $form + ['id' => ['type' => 'integer', 'minimum' => 1]];
        $page = ['page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]];
        $write = fn ($name, $description, $properties, $required) => ['name' => 'submissions_'.$name, 'description' => $description.' Full credential scope and admin/editor role required. Send current revision and a unique operation_key; identical retries recover the original result.', 'inputSchema' => ['type' => 'object', 'properties' => $entity + ['expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64], 'operation_key' => ['type' => 'string', 'maxLength' => 100]] + $properties, 'required' => array_merge(['form', 'id', 'expected_revision', 'operation_key'], $required)]];

        return [
            ['name' => 'submissions_list', 'description' => 'Search the code-owned form inbox in bounded pages. Read-only; never marks submissions read. Filters by workflow, assignee, delivery, read state and dates.', 'inputSchema' => ['type' => 'object', 'properties' => $form + $page + ['search' => ['type' => 'string', 'maxLength' => 200], 'status' => ['type' => 'string', 'enum' => ['new', 'read']], 'workflow' => ['type' => 'string', 'enum' => ['new', 'in_progress', 'resolved']], 'assignee' => ['type' => ['integer', 'string'], 'description' => 'User ID or unassigned.'], 'notification' => ['type' => 'string', 'enum' => ['skipped', 'queued', 'sending', 'retrying', 'failed', 'sent']], 'from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date']], 'required' => ['form']]],
            ['name' => 'submissions_get', 'description' => 'Read a submission and paginated authored notes without changing read state. Recover long visitor text using field/offset/length. Delivery tokens and transport secrets are excluded.', 'inputSchema' => ['type' => 'object', 'properties' => $entity + ['notes_page' => ['type' => 'integer', 'minimum' => 1], 'field' => ['type' => 'string', 'maxLength' => 100], 'offset' => ['type' => 'integer', 'minimum' => 0], 'length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8192]], 'required' => ['form', 'id']]],
            $write('assign', 'Assign a responsible administrator/editor, or clear assignment with null.', ['assigned_to' => ['type' => ['integer', 'null'], 'minimum' => 1]], ['assigned_to']),
            $write('set_status', 'Set operational follow-up state independently of read state; visitor fields remain immutable.', ['workflow_status' => ['type' => 'string', 'enum' => ['new', 'in_progress', 'resolved']]], ['workflow_status']),
            $write('add_note', 'Append an internal note attributed to the bearer owner. Notes never enter public pages or mail.', ['body' => ['type' => 'string', 'maxLength' => 5000]], ['body']),
            $write('delete', 'Permanently delete a submission with its internal notes (for example a privacy erasure request). The deletion is audited.', [], []),
            $write('retry_notification', 'Reserve a retry only for failed/skipped delivery using the code-owned notification configuration. Pending/sent generations do not send again. Query delivery state after processing; provider acceptance followed by connection failure cannot guarantee exactly-once.', [], []),
        ];
    }

    private static function editorialTools(): array
    {
        $entity = ['type' => ['type' => 'string', 'enum' => ['pages', 'posts']], 'id' => ['type' => 'integer', 'minimum' => 1]];
        $revision = ['expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]];
        $key = ['operation_key' => ['type' => 'string', 'maxLength' => 100]];
        $version = ['version_id' => ['type' => 'integer', 'minimum' => 1]];
        $tool = fn ($name, $description, $properties, $required) => ['name' => 'content_'.$name, 'description' => $description, 'inputSchema' => ['type' => 'object', 'properties' => $entity + $properties, 'required' => array_merge(['type', 'id'], $required)]];
        $write = fn ($name, $description, $properties, $required) => $tool($name, $description.' Requires current expected_revision and an operation_key; identical retries recover the original result.', $revision + $key + $properties, array_merge(['expected_revision', 'operation_key'], $required));

        return [
            $tool('versions', 'Read full history/proposal metadata in bounded pages without creating versions.', ['page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]], []),
            $tool('version', 'Read a selected immutable version. Large field values are recoverable with field/offset/length; fingerprints stay intact.', $version + ['field' => ['type' => 'string', 'maxLength' => 500], 'offset' => ['type' => 'integer', 'minimum' => 0], 'length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8192]], ['version_id']),
            $tool('diff', 'Compare an immutable version against current content or another version; paginated paths and field ranges recover large differences.', $version + ['compare_version_id' => ['type' => 'integer', 'minimum' => 1], 'page' => ['type' => 'integer', 'minimum' => 1], 'field' => ['type' => 'string', 'maxLength' => 500], 'offset' => ['type' => 'integer', 'minimum' => 0], 'length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8192]], ['version_id']),
            $tool('reviews', 'Read review requests and authored comments in bounded pages; recover comment body with review_id/comment_id and text ranges, or field=comment for the request note.', ['page' => ['type' => 'integer', 'minimum' => 1], 'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'review_id' => ['type' => 'integer', 'minimum' => 1], 'comment_id' => ['type' => 'integer', 'minimum' => 1], 'comments_page' => ['type' => 'integer', 'minimum' => 1], 'field' => ['type' => 'string', 'enum' => ['comment']], 'offset' => ['type' => 'integer', 'minimum' => 0], 'length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8192]], []),
            $write('propose', 'Stage validated changes as a proposal while preserving live content. Draft-only credentials may propose published changes.', ['changes' => ['type' => 'object']], ['changes']),
            $write('review_request', 'Request an assigned administrator to review an exact proposal version; draft-only credentials may request review.', $version + ['reviewer_id' => ['type' => 'integer', 'minimum' => 1], 'comment' => ['type' => 'string', 'maxLength' => 10000]], ['reviewer_id', 'version_id']),
            $write('review_decide', 'Assigned administrator/full scope only: approve the exact requested version or request changes. A changed base rejects approval atomically.', ['review_id' => ['type' => 'integer', 'minimum' => 1], 'state' => ['type' => 'string', 'enum' => ['approved', 'changes_requested']], 'comment' => ['type' => 'string', 'maxLength' => 10000]], ['review_id', 'state']),
            $write('review_comment', 'Add an authored review comment; draft-only credentials may comment.', ['review_id' => ['type' => 'integer', 'minimum' => 1], 'body' => ['type' => 'string', 'maxLength' => 10000]], ['review_id', 'body']),
            $write('restore', 'Restore a version as a proposal preserving live publication, or explicitly as a draft withdrawing publication (administrator/full scope for published records). Associations/media retained when available.', $version + ['mode' => ['type' => 'string', 'enum' => ['draft', 'proposal']]], ['version_id', 'mode']),
            $write('preview_revoke', 'Revoke a shared preview issued by this actor, or any link as administrator.', ['link_id' => ['type' => 'integer', 'minimum' => 1]], ['link_id']),
        ];
    }

    private static function phaseThreeTools(): array
    {
        $entity = ['type' => ['type' => 'string', 'enum' => ['pages', 'posts']], 'id' => ['type' => 'integer', 'minimum' => 1]];
        $revision = ['expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]];
        $tool = fn (string $name, string $description, array $properties = [], array $required = []) => ['name' => $name, 'description' => $description, 'inputSchema' => ['type' => 'object', 'properties' => $properties ?: new \stdClass, 'required' => $required]];

        return [
            $tool('settings_update_batch', 'Admin/full scope: atomically configure allowed layout, contact and SEO settings. One canonical row per fact; localized values only on supported keys. Secrets are excluded.', ['settings' => ['type' => 'object', 'properties' => array_fill_keys(config('cms.mcp.settings_writable_keys', []), new \stdClass), 'additionalProperties' => false], 'locale' => ['type' => 'string', 'enum' => Locale::installedCodes()], 'expected_revisions' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]]], ['settings']),
            $tool('settings_reset', 'Admin/full scope: restore writable settings to their starter value. Without locale the value and all localized overrides are reset; with a non-base locale only that override is removed and the base value applies.', ['keys' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'enum' => config('cms.mcp.settings_writable_keys', [])]], 'locale' => ['type' => 'string', 'enum' => Locale::installedCodes()], 'expected_revisions' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]]], ['keys']),
            $tool('content_read_field', 'Read an editorial string in character ranges or an array in item ranges after a clipped detail response. Check revision between reads.', $entity + ['field' => ['type' => 'string', 'maxLength' => 500], 'offset' => ['type' => 'integer', 'minimum' => 0], 'length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8192]], ['type', 'id', 'field']),
            $tool('cms_schema', 'Discover the exact editorial schema, locale rules and creation example for an installed content type/template.', ['type' => ['type' => 'string', 'enum' => ['pages', 'posts']], 'template_key' => ['type' => 'string', 'enum' => array_keys(config('cms.templates', []))]], ['type']),
            $tool('locales_list', 'List installed languages, activation/publication flags and installable catalog.'),
            $tool('locales_configure', 'Admin only: install a catalog language and/or patch activation/publication flags. Base language stays active/public.', ['install' => ['type' => 'string', 'enum' => Locale::catalogCodes()], 'locales' => ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => ['is_active' => ['type' => 'boolean'], 'is_public' => ['type' => 'boolean']], 'additionalProperties' => false]]]),
            $tool('content_translate', 'Create a draft translation in the same group, preserving media and remapping associations. Identical retries return the existing translation. Then patch translated copy and slug.', $entity + ['locale' => ['type' => 'string', 'enum' => Locale::installedCodes()]], ['type', 'id', 'locale']),
            $tool('content_translation_changes', 'Inspect source changes in pages of 100 fields. Text ranges default to 256 characters; select field/offset/length for its complete review. Hashes remain intact.', $entity + ['page' => ['type' => 'integer', 'minimum' => 1], 'field' => ['type' => 'string', 'maxLength' => 500], 'offset' => ['type' => 'integer', 'minimum' => 0], 'length' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8192]], ['type', 'id']),
            $tool('content_translation_acknowledge', 'Acknowledge a reviewed source field on a draft translation; stale source hash/revision fails without loss.', $entity + $revision + ['field' => ['type' => 'string', 'maxLength' => 500], 'base_hash' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64]], ['type', 'id', 'field', 'base_hash']),
            $tool('content_publication', 'Admin/full scope only: schedule, withdraw publication, or configure future expiry (null clears it). Public queries honor both dates.', $entity + $revision + ['action' => ['type' => 'string', 'enum' => ['schedule', 'unpublish', 'expire']], 'published_at' => ['type' => 'string', 'format' => 'date-time'], 'expires_at' => ['type' => ['string', 'null'], 'format' => 'date-time']], ['type', 'id', 'action']),
            $tool('content_preview', 'Issue a preview pinned to an exact history/proposal version without a browser session. Later edits cannot alter it. Confidential URL; expiry 1–168 hours; revoke with content_preview_revoke.', $entity + ['version_id' => ['type' => 'integer', 'minimum' => 1], 'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64], 'hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 168]], ['type', 'id']),
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
                'name' => 'pages_update',
                'description' => 'Edit a page like the Live Editor: patch a draft, or as an administrator change published content live (it stays published). Same patch rules as pages_update_draft. Editors change published pages through content_propose. Full credential scope; requires the current revision.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['page_id', 'expected_revision'],
                    'properties' => [
                        'page_id' => ['type' => 'integer', 'minimum' => 1],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                        'title' => ['type' => 'string'],
                        'slug' => ['type' => 'string'],
                        'template_key' => ['type' => 'string', 'enum' => array_keys(config('cms.templates', []))],
                        'content_json' => ['type' => 'object', 'description' => 'Patch: objects merge keys, lists are replaced whole, null removes a key; omitted keys are kept.'],
                        'featured_image' => ['type' => ['integer', 'null'], 'description' => 'null removes the image.'],
                    ],
                ],
            ],
            [
                'name' => 'pages_delete',
                'description' => 'Permanently delete a page (draft or published). Menu links to it are removed and reported in warnings; its translations are kept and reported. The base-language homepage cannot be deleted. Editors cannot delete published pages. Full credential scope; requires the current revision.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['page_id', 'expected_revision'],
                    'properties' => [
                        'page_id' => ['type' => 'integer', 'minimum' => 1],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
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
                'name' => 'posts_update',
                'description' => 'Edit a post like the Live Editor: patch a draft, or as an administrator change published content live (it stays published). Same fields and meta_json patch rules as posts_update_draft. Editors change published posts through content_propose. Full credential scope; requires the current revision.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['post_id', 'expected_revision'],
                    'properties' => [
                        'post_id' => ['type' => 'integer', 'minimum' => 1],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
                        'categories' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'title' => ['type' => 'string'],
                        'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$'],
                        'excerpt' => ['type' => ['string', 'null']],
                        'content' => ['type' => ['string', 'null']],
                        'featured_image' => ['type' => ['integer', 'null']],
                        'meta_json' => ['type' => ['object', 'null'], 'description' => 'SEO key patch; null on a key removes it, null clears all SEO.'],
                    ],
                ],
            ],
            [
                'name' => 'posts_delete',
                'description' => 'Permanently delete a post (draft or published). Menu links to it are removed and reported in warnings; its translations are kept and reported. Full credential scope and delete permission; requires the current revision.',
                'inputSchema' => [
                    'type' => 'object',
                    'required' => ['post_id', 'expected_revision'],
                    'properties' => [
                        'post_id' => ['type' => 'integer', 'minimum' => 1],
                        'expected_revision' => ['type' => 'string', 'minLength' => 64, 'maxLength' => 64],
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
