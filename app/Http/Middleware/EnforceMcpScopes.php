<?php

namespace App\Http\Middleware;

use App\Models\Page;
use App\Models\Post;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnforceMcpScopes
{
    private const READ = ['cms_capabilities', 'content_read_field', 'cms_schema', 'locales_list', 'content_translation_changes', 'media_usages', 'pages_list', 'pages_get', 'posts_list', 'posts_get', 'media_list', 'media_get', 'settings_list', 'settings_get', 'menus_list', 'menus_get'];

    private const DRAFT = ['content_translate', 'content_translation_acknowledge', 'content_preview', 'pages_create_draft', 'pages_update_draft', 'posts_create_draft', 'posts_update_draft'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('mcp_scope') === 'draft-only') {
            return DB::transaction(fn () => $this->authorizeScope($request, $next), 3);
        }

        return $this->authorizeScope($request, $next);
    }

    private function authorizeScope(Request $request, Closure $next): Response
    {
        $scope = $request->attributes->get('mcp_scope', 'full');
        if ($scope === 'full') {
            return $next($request);
        }
        if ($request->is('mcp/rpc')) {
            if (in_array($request->input('method'), ['initialize', 'tools/list'], true)) {
                return $next($request);
            }
            $tool = $request->input('params.name');
            $allowed = in_array($tool, self::READ, true) || ($scope === 'draft-only' && in_array($tool, self::DRAFT, true));
            if ($allowed && in_array($tool, ['pages_update_draft', 'posts_update_draft', 'content_translation_acknowledge', 'content_preview'], true)) {
                $generic = str_starts_with($tool, 'content_');
                $model = $generic ? ($request->input('params.arguments.type') === 'pages' ? Page::class : Post::class) : ($tool === 'pages_update_draft' ? Page::class : Post::class);
                $id = $request->input('params.arguments.'.($generic ? 'id' : ($model === Page::class ? 'page_id' : 'post_id')));
                $allowed = $model::whereKey($id)->where('status', 'draft')->lockForUpdate()->first() !== null;
            }
            if (! $allowed) {
                return response()->json(['jsonrpc' => '2.0', 'id' => $request->input('id'),
                    'error' => ['code' => -32603, 'message' => __('security.scope_denied')]], 403);
            }
        } else {
            $allowed = $request->isMethod('GET');
            if ($scope === 'draft-only') {
                $allowed = $allowed || ($request->isMethod('POST') && preg_match('#^mcp/(pages|posts)/drafts$#D', $request->path()));
                if ($request->isMethod('POST') && preg_match('#^mcp/content/(pages|posts)/([0-9]+)/(translation|translation-acknowledge|preview)$#D', $request->path(), $matches)) {
                    $model = $matches[1] === 'pages' ? Page::class : Post::class;
                    $allowed = $matches[3] === 'translation' || $model::whereKey($matches[2])->where('status', 'draft')->lockForUpdate()->first() !== null;
                }
                if ($request->isMethod('PATCH') && preg_match('#^mcp/(pages|posts)/([0-9]+)/draft$#D', $request->path(), $matches)) {
                    $model = $matches[1] === 'pages' ? Page::class : Post::class;
                    $allowed = $model::whereKey($matches[2])->where('status', 'draft')->lockForUpdate()->first() !== null;
                }
            }
            if (! $allowed) {
                return response()->json(['message' => __('security.scope_denied')], 403);
            }
        }

        return $next($request);
    }
}
