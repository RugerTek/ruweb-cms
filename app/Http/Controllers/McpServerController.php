<?php

namespace App\Http\Controllers;

use App\Mcp\ToolRegistry;
use App\Models\Media;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON-RPC 2.0 MCP server endpoint.
 *
 * All calls arrive at POST /mcp/rpc. The dispatcher resolves the method,
 * builds a synthetic Request with the tool arguments, and delegates to
 * the existing McpController without duplicating any business logic.
 */
class McpServerController extends Controller
{
    private const PROTOCOL_VERSION = '2024-11-05';

    public function __construct(private McpController $mcp) {}

    public function handle(Request $request): JsonResponse
    {
        $body = $request->json()->all();

        if (($body['jsonrpc'] ?? null) !== '2.0') {
            return $this->errorResponse(null, -32600, 'Invalid Request: jsonrpc must be "2.0".');
        }

        $method         = $body['method'] ?? null;
        $params         = $body['params'] ?? [];
        $id             = $body['id'] ?? null;
        $isNotification = ! array_key_exists('id', $body);

        // JSON-RPC notifications must not receive a response
        if ($isNotification) {
            return response()->json(null, 202);
        }

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize(),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($request, $params),
                default      => throw new \InvalidArgumentException("Method not found: {$method}", -32601),
            };
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse($id, -32602, 'Validation failed.', $e->errors());
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return $this->errorResponse($id, -32603, $e->getMessage());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->errorResponse($id, -32602, 'Resource not found.');
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($id, $e->getCode() ?: -32600, $e->getMessage());
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ]);
    }

    // -------------------------------------------------------------------------
    // Protocol handlers
    // -------------------------------------------------------------------------

    private function handleInitialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => ['tools' => new \stdClass()],
            'serverInfo'      => [
                'name'    => config('app.name', 'ruweb-cms'),
                'version' => '1.0.0',
            ],
        ];
    }

    private function handleToolsList(): array
    {
        return ['tools' => ToolRegistry::all()];
    }

    private function handleToolsCall(Request $request, array $params): array
    {
        $name      = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (! $name) {
            throw new \InvalidArgumentException('Missing required param: name.', -32602);
        }

        $handler = $this->resolveHandler($name);

        if (! $handler) {
            throw new \InvalidArgumentException("Unknown tool: {$name}.", -32601);
        }

        $response = $handler($request, $arguments);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($response->getData(true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Tool dispatcher
    // -------------------------------------------------------------------------

    private function resolveHandler(string $name): ?\Closure
    {
        $handlers = [
            // Pages
            'pages_list' => fn ($req, $args) =>
                $this->mcp->pagesList($this->synth($req, $args)),

            'pages_get' => fn ($req, $args) =>
                $this->mcp->pagesGet($this->synth($req), Page::findOrFail($args['page_id'])),

            'pages_create_draft' => fn ($req, $args) =>
                $this->mcp->pagesCreateDraft($this->synth($req, $args)),

            'pages_update_draft' => fn ($req, $args) =>
                $this->mcp->pagesUpdateDraft(
                    $this->synth($req, $this->without($args, 'page_id')),
                    Page::findOrFail($args['page_id'])
                ),

            'pages_publish' => fn ($req, $args) =>
                $this->mcp->pagesPublish($this->synth($req), Page::findOrFail($args['page_id'])),

            // Posts
            'posts_list' => fn ($req, $args) =>
                $this->mcp->postsList($this->synth($req, $args)),

            'posts_get' => fn ($req, $args) =>
                $this->mcp->postsGet($this->synth($req), Post::findOrFail($args['post_id'])),

            'posts_create_draft' => fn ($req, $args) =>
                $this->mcp->postsCreateDraft($this->synth($req, $args)),

            'posts_update_draft' => fn ($req, $args) =>
                $this->mcp->postsUpdateDraft(
                    $this->synth($req, $this->without($args, 'post_id')),
                    Post::findOrFail($args['post_id'])
                ),

            'posts_publish' => fn ($req, $args) =>
                $this->mcp->postsPublish($this->synth($req), Post::findOrFail($args['post_id'])),

            // Media
            'media_list' => fn ($req, $args) =>
                $this->mcp->mediaList($this->synth($req, $args)),

            'media_get' => fn ($req, $args) =>
                $this->mcp->mediaGet($this->synth($req, $this->without($args, 'media_id')), Media::findOrFail($args['media_id'])),

            'media_update_metadata' => fn ($req, $args) =>
                $this->mcp->mediaAttachMetadata(
                    $this->synth($req, $this->without($args, 'media_id')),
                    Media::findOrFail($args['media_id'])
                ),

            // Settings
            'settings_list' => fn ($req, $args) =>
                $this->mcp->settingsListGrouped($this->synth($req)),

            'settings_get' => fn ($req, $args) =>
                $this->mcp->settingsGet($this->synth($req), $args['key']),

            'settings_update' => fn ($req, $args) =>
                $this->mcp->settingsUpdate(
                    $this->synth($req, $this->without($args, 'key')),
                    $args['key']
                ),

            // Menus
            'menus_list' => fn ($req, $args) =>
                $this->mcp->menusList($this->synth($req)),

            'menus_get' => fn ($req, $args) =>
                $this->mcp->menusGet($this->synth($req), Menu::findOrFail($args['menu_id'])),

            'menus_create' => fn ($req, $args) =>
                $this->mcp->menusCreate($this->synth($req, $args)),

            'menus_update' => fn ($req, $args) =>
                $this->mcp->menusUpdate(
                    $this->synth($req, $this->without($args, 'menu_id')),
                    Menu::findOrFail($args['menu_id'])
                ),

            'menus_sync_items' => fn ($req, $args) =>
                $this->mcp->menusSyncItems(
                    $this->synth($req, $this->without($args, 'menu_id')),
                    Menu::findOrFail($args['menu_id'])
                ),

            'menus_delete' => fn ($req, $args) =>
                $this->mcp->menusDelete($this->synth($req), Menu::findOrFail($args['menu_id'])),
        ];

        return $handlers[$name] ?? null;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build a synthetic Request that carries the given data and the
     * authenticated user from the original request.
     */
    private function synth(Request $original, array $data = []): Request
    {
        $synthetic = Request::create('/', 'POST', $data);
        $synthetic->setUserResolver(fn () => $original->user());

        return $synthetic;
    }

    /**
     * Return the array without the given keys (used to strip route-level IDs
     * before passing the rest as request body).
     */
    private function without(array $args, string ...$keys): array
    {
        return array_diff_key($args, array_flip($keys));
    }

    private function errorResponse(mixed $id, int $code, string $message, mixed $data = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => $error,
        ]);
    }
}
