<?php

namespace App\Http\Controllers;

use App\Exceptions\RevisionConflict;
use App\Mcp\ToolRegistry;
use App\Mcp\ToolArguments;
use App\Models\Media;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Post;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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

    /** Implementation-defined JSON-RPC code for authorization refusals; -32603 stays reserved for real failures. */
    public const FORBIDDEN = -32003;

    public function __construct(private McpController $mcp, private McpContentController $content, private McpMediaController $media, private McpSubmissionController $submissions, private McpCustomCodeController $customCode) {}

    public function handle(Request $request): JsonResponse
    {
        $body = $request->json()->all();

        if (($body['jsonrpc'] ?? null) !== '2.0') {
            return $this->errorResponse(null, -32600, 'Invalid Request: jsonrpc must be "2.0".');
        }

        $method = $body['method'] ?? null;
        $params = $body['params'] ?? [];
        $id = $body['id'] ?? null;
        $isNotification = ! array_key_exists('id', $body);

        if (! is_string($method) || $method === '' || ! is_array($params)
            || ($id !== null && ! is_string($id) && ! is_int($id) && ! is_float($id))) {
            return $this->errorResponse(null, -32600, 'Invalid Request: method, params or id has an invalid type.');
        }

        // JSON-RPC notifications must not receive a response
        if ($isNotification) {
            return response()->json(null, 202);
        }

        try {
            $result = match ($method) {
                'initialize' => $this->handleInitialize(),
                'tools/list' => $this->handleToolsList(),
                'tools/call' => $this->handleToolsCall($request, $params),
                'ping' => new \stdClass,
                default => throw new \InvalidArgumentException("Method not found: {$method}", -32601),
            };
        } catch (ValidationException $e) {
            return $this->errorResponse($id, -32602, $this->validationMessage($e->errors()), $e->errors());
        } catch (AuthorizationException $e) {
            return $this->errorResponse($id, self::FORBIDDEN, $e->getMessage(), ['reason' => 'forbidden', 'recovery' => 'Not a server failure. The account role or record state does not allow this operation.']);
        } catch (ModelNotFoundException) {
            return $this->errorResponse($id, -32602, 'Resource not found.');
        } catch (RevisionConflict $e) {
            return $this->errorResponse($id, -32009, $e->getMessage(), $e->diagnostic());
        } catch (HttpExceptionInterface $e) {
            return $this->errorResponse($id, $e->getStatusCode() === 409 ? -32009 : -32602, $e->getMessage(), ['status' => $e->getStatusCode(), 'recovery' => $e->getStatusCode() === 409 ? 'Read current revision, merge changes and retry.' : 'Correct the input and retry.']);
        } catch (\InvalidArgumentException $e) {
            return $this->errorResponse($id, $e->getCode() ?: -32600, $e->getMessage());
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    // -------------------------------------------------------------------------
    // Protocol handlers
    // -------------------------------------------------------------------------

    private function handleInitialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => new \stdClass],
            'serverInfo' => [
                'name' => config('app.name', 'ruweb-cms'),
                'version' => config('cms.version', '0.0.0'),
            ],
        ];
    }

    private function handleToolsList(): array
    {
        return ['tools' => ToolRegistry::all()];
    }

    private function handleToolsCall(Request $request, array $params): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (! is_string($name) || ! is_array($arguments)) {
            throw new \InvalidArgumentException('Tool name must be a string and arguments must be an object.', -32602);
        }

        if (! $name) {
            throw new \InvalidArgumentException('Missing required param: name.', -32602);
        }

        $handler = $this->resolveHandler($name);
        $schema = null;
        foreach (ToolRegistry::all() as $tool) {
            if ($tool['name'] === $name) {
                $schema = $tool['inputSchema'];
                break;
            }
        }
        if (! $handler || $schema === null) {
            throw new \InvalidArgumentException("Unknown tool: {$name}.", -32601);
        }
        ToolArguments::validate($arguments, $schema);

        $response = $handler($request, $arguments);

        return [
            // A valid tool call can fail at the application layer (e.g. 404/422).
            // Keep the controller's diagnostic and expose failure to MCP clients.
            'isError' => $response->getStatusCode() >= 400,
            'content' => [
                [
                    'type' => 'text',
                    'text' => $response->getContent(),
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
            'settings_update_batch' => fn ($req, $args) => $this->mcp->settingsUpdateBatch($this->synth($req, $args)),
            'content_read_field' => fn ($req, $args) => $this->content->readField($this->synth($req, $args), $args['type'], $args['id']),
            'cms_schema' => fn ($req, $args) => $this->content->schema($this->synth($req, $args)),
            'locales_list' => fn ($req, $args) => $this->content->locales($this->synth($req, $args)),
            'locales_configure' => fn ($req, $args) => $this->content->configureLocales($this->synth($req, $args)),
            'content_translate' => fn ($req, $args) => $this->content->translation($this->synth($req, $args), $args['type'], $args['id']),
            'content_translation_changes' => fn ($req, $args) => $this->content->changes($this->synth($req, $args), $args['type'], $args['id']),
            'content_translation_acknowledge' => fn ($req, $args) => $this->content->acknowledge($this->synth($req, $args), $args['type'], $args['id']),
            'content_publication' => fn ($req, $args) => $this->content->publication($this->synth($req, $args), $args['type'], $args['id']),
            'content_preview' => fn ($req, $args) => $this->content->preview($this->synth($req, $args), $args['type'], $args['id']),
            'media_authorize_upload' => fn ($req, $args) => $this->media->authorizeUpload($this->synth($req, $args)),
            'media_usages' => fn ($req, $args) => $this->media->usages($this->synth($req, $args), Media::findOrFail($args['media_id'])),
            'media_delete' => fn ($req, $args) => $this->media->destroy($this->synth($req, $args), Media::findOrFail($args['media_id'])),
            'cms_capabilities' => fn ($req, $args) => $this->mcp->capabilities($this->synth($req)),
            // Pages
            'pages_list' => fn ($req, $args) => $this->mcp->pagesList($this->synth($req, $args)),

            'pages_get' => fn ($req, $args) => $this->mcp->pagesGet($this->synth($req, $args), Page::findOrFail($args['page_id'])),

            'pages_create_draft' => fn ($req, $args) => $this->mcp->pagesCreateDraft($this->synth($req, $args)),

            'pages_update_draft' => fn ($req, $args) => $this->mcp->pagesUpdateDraft(
                $this->synth($req, $this->without($args, 'page_id')),
                Page::findOrFail($args['page_id'])
            ),

            'pages_publish' => fn ($req, $args) => $this->mcp->pagesPublish($this->synth($req, $args), Page::findOrFail($args['page_id'])),

            // Posts
            'posts_list' => fn ($req, $args) => $this->mcp->postsList($this->synth($req, $args)),

            'posts_get' => fn ($req, $args) => $this->mcp->postsGet($this->synth($req, $args), Post::findOrFail($args['post_id'])),

            'posts_create_draft' => fn ($req, $args) => $this->mcp->postsCreateDraft($this->synth($req, $args)),

            'posts_update_draft' => fn ($req, $args) => $this->mcp->postsUpdateDraft(
                $this->synth($req, $this->without($args, 'post_id')),
                Post::findOrFail($args['post_id'])
            ),

            'posts_publish' => fn ($req, $args) => $this->mcp->postsPublish($this->synth($req, $args), Post::findOrFail($args['post_id'])),

            // Media
            'media_list' => fn ($req, $args) => $this->mcp->mediaList($this->synth($req, $args)),

            'media_get' => fn ($req, $args) => $this->mcp->mediaGet($this->synth($req, $this->without($args, 'media_id')), Media::findOrFail($args['media_id'])),

            'media_update_metadata' => fn ($req, $args) => $this->mcp->mediaAttachMetadata(
                $this->synth($req, $this->without($args, 'media_id')),
                Media::findOrFail($args['media_id'])
            ),

            // Settings
            'settings_list' => fn ($req, $args) => $this->mcp->settingsListGrouped($this->synth($req)),

            'settings_get' => fn ($req, $args) => $this->mcp->settingsGet($this->synth($req), $args['key']),

            'settings_update' => fn ($req, $args) => $this->mcp->settingsUpdate(
                $this->synth($req, $this->without($args, 'key')),
                $args['key']
            ),

            // Menus
            'menus_list' => fn ($req, $args) => $this->mcp->menusList($this->synth($req, $args)),

            'menus_get' => fn ($req, $args) => $this->mcp->menusGet($this->synth($req), Menu::findOrFail($args['menu_id'])),

            'menus_create' => fn ($req, $args) => $this->mcp->menusCreate($this->synth($req, $args)),

            'menus_update' => fn ($req, $args) => $this->mcp->menusUpdate(
                $this->synth($req, $this->without($args, 'menu_id')),
                Menu::findOrFail($args['menu_id'])
            ),

            'menus_sync_items' => fn ($req, $args) => $this->mcp->menusSyncItems(
                $this->synth($req, $this->without($args, 'menu_id')),
                Menu::findOrFail($args['menu_id'])
            ),

            'menus_delete' => fn ($req, $args) => $this->mcp->menusDelete($this->synth($req, $args), Menu::findOrFail($args['menu_id'])),
        ];

        $handlers['custom_code_list'] = fn ($req, $args) => $this->customCode->index($this->synth($req, $args));
        $handlers['custom_code_get'] = fn ($req, $args) => $this->customCode->show($this->synth($req, $args), $args['id']);
        $handlers['custom_code_save'] = fn ($req, $args) => $this->customCode->save($this->synth($req, $this->without($args, 'id')), $args['id'] ?? null);
        $handlers['custom_code_activate'] = fn ($req, $args) => $this->customCode->activate($this->synth($req, $this->without($args, 'id')), $args['id']);
        $handlers['custom_code_deactivate'] = fn ($req, $args) => $this->customCode->deactivate($this->synth($req, $this->without($args, 'id')), $args['id']);
        $handlers['custom_code_evaluate'] = fn ($req, $args) => $this->customCode->evaluate($this->synth($req, $args));
        $handlers['custom_code_destinations'] = fn ($req, $args) => $this->customCode->destinations($this->synth($req, $args));

        $handlers['submissions_list'] = fn ($req, $args) => $this->submissions->index($this->synth($req, $args), $args['form']);
        $handlers['submissions_get'] = fn ($req, $args) => $this->submissions->show($this->synth($req, $args), $args['form'], $args['id']);
        foreach (['assign', 'set_status', 'add_note', 'retry_notification'] as $action) {
            $handlers['submissions_'.$action] = fn ($req, $args) => $this->submissions->write($this->synth($req, $args), $args['form'], $args['id'], $action);
        }

        foreach (['versions', 'version', 'diff', 'reviews'] as $action) {
            $handlers['content_'.$action] = fn ($req, $args) => $this->content->editorialRead($this->synth($req, $args), $args['type'], $args['id'], $action);
        }
        foreach (['propose', 'review_request', 'review_decide', 'review_comment', 'restore', 'preview_revoke'] as $action) {
            $handlers['content_'.$action] = fn ($req, $args) => $this->content->editorialWrite($this->synth($req, $args), $args['type'], $args['id'], $action);
        }

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
        $synthetic->headers->set('Authorization', (string) $original->header('Authorization'));
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

    /** Many clients show agents only `error.message`, so it names the fields to correct; `data` keeps them all. */
    private function validationMessage(array $errors): string
    {
        $fields = array_map(fn ($field, $messages) => $field.': '.($messages[0] ?? 'invalid'), array_keys($errors), $errors);
        $message = 'Validation failed. '.implode(' ', array_slice($fields, 0, 5)).(count($fields) > 5 ? ' (+'.(count($fields) - 5).' more in error.data)' : '');

        return mb_strlen($message) > 1000 ? mb_substr($message, 0, 997).'...' : $message;
    }

    private function errorResponse(mixed $id, int $code, string $message, mixed $data = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ]);
    }
}
