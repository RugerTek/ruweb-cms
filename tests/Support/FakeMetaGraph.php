<?php

namespace Tests\Support;

use App\Connectors\Meta\Graph\HttpMetaGraphClient;
use App\Connectors\Meta\Graph\MetaUsage;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Double of Meta's Graph API for the Meta v2 tracks, installed with Http::fake on `cms.meta.graph_url`: no request
 * ever reaches Meta. It answers with the REAL bodies, statuses and `x-business-use-case-usage` headers recorded by the
 * spike (tests/Fixtures/meta-graph/spike-v27.json, from spike-meta-v2/03-api/calls.jsonl, v27.0, 2026-11-24): the
 * catalog/feed/upload/items_batch flow on a `commerce` catalog, upload errors (feed_file_not_found, wrong_format),
 * batch warnings and errors, and the failures 190/464 (app removed), 100/33 (catalog unshared), 10/1690129 (create a
 * catalog) and 200 (portfolio edges need business_management).
 *
 * Every request must carry `Authorization: Bearer <token>` with the expected token and never the token in the URL;
 * otherwise the fake answers a 190 (the only synthesized body, marked `fbtrace_id: FakeMetaGraph`). Switch
 * scenarios with removeApp(), unshareCatalog(), failUploads() and batchOutcome(); read what was sent with calls().
 */
final class FakeMetaGraph
{
    public const TOKEN = 'EAAFakeSystemUserToken0123456789abcdefghijWXYZ';

    public const BUSINESS_ID = '1828876861482638';

    /** The `commerce` catalog of the spike (feed, uploads and items_batch worked there). */
    public const CATALOG_ID = '1140865528694664';

    /** The `generic` catalog «Crear catálogo» of Meta's dialog made: rejects the feed and items_batch. */
    public const GENERIC_CATALOG_ID = '1652153386366048';

    /** The catalog the client already had (Ruweb prueba feed). */
    public const OTHER_CATALOG_ID = '29126310230344924';

    public const FEED_ID = '1449316787271140';

    public const UPLOAD_ID = '1449316803937805';

    public const FAILED_UPLOAD_ID = '1449317557271063';

    public const BATCH_OK = 'ok';

    /** UPDATE with an invalid value: errors_total_count 0, the problem in `warnings` (spike §3.3.4). */
    public const BATCH_WARNINGS = 'warnings';

    /** Items rejected for the catalog vertical (generic catalog): every item in `errors`. */
    public const BATCH_ERRORS = 'errors';

    public const FIXTURE = 'tests/Fixtures/meta-graph/spike-v27.json';

    /** @var array<string, array{label: string, method: string, path: string, status: int, body: mixed, usage: string|null}> */
    private array $responses;

    /** @var list<array{method: string, path: string, query: array<string, mixed>, form: array<string, mixed>, authorization: string|null, url: string}> */
    private array $calls = [];

    private bool $appRemoved = false;

    /** @var array<string, true> */
    private array $unshared = [];

    private bool $uploadsFail = false;

    private string $batchOutcome = self::BATCH_OK;

    private bool $feedUpdated = false;

    public function __construct(private string $token = self::TOKEN)
    {
        $json = json_decode((string) file_get_contents(base_path(self::FIXTURE)), true);
        if (! is_array($json) || ! is_array($json['responses'] ?? null)) {
            throw new RuntimeException('Unreadable '.self::FIXTURE);
        }
        $this->responses = $json['responses'];
    }

    /** Install the fake for `cms.meta.graph_url` and return it. */
    public static function install(string $token = self::TOKEN): self
    {
        $fake = new self($token);
        Http::fake([rtrim((string) config('cms.meta.graph_url'), '/').'/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /** The client removed the app in Business settings: every call answers 190/464. */
    public function removeApp(): self
    {
        $this->appRemoved = true;

        return $this;
    }

    /** The client removed this catalog from the app: it answers 100/33 and leaves the assigned list. */
    public function unshareCatalog(string $catalogId = self::CATALOG_ID): self
    {
        $this->unshared[$catalogId] = true;

        return $this;
    }

    /** Uploads fetch a URL that answers 404: error_count 1, feed_file_not_found. */
    public function failUploads(): self
    {
        $this->uploadsFail = true;

        return $this;
    }

    public function batchOutcome(string $outcome): self
    {
        $this->batchOutcome = $outcome;

        return $this;
    }

    /** @return list<array{method: string, path: string, query: array<string, mixed>, form: array<string, mixed>, authorization: string|null, url: string}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * A recorded answer of the spike (status, body, usage header).
     *
     * @return array{label: string, method: string, path: string, status: int, body: mixed, usage: string|null}
     */
    public function response(string $name): array
    {
        return $this->responses[$name] ?? throw new RuntimeException('No spike response "'.$name.'".');
    }

    public function handle(Request $request): mixed
    {
        $prefix = HttpMetaGraphClient::baseUrl().'/';
        $url = $request->url();
        $path = str_starts_with($url, $prefix) ? (string) parse_url(substr($url, strlen($prefix) - 1), PHP_URL_PATH) : '';
        $path = trim($path, '/');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $form = [];
        if ($request->method() === 'POST') {
            parse_str($request->body(), $form);
        }
        $authorization = $request->header('Authorization')[0] ?? null;
        $this->calls[] = ['method' => $request->method(), 'path' => $path, 'query' => $query, 'form' => $form, 'authorization' => $authorization, 'url' => $url];

        if ($path === '' || $authorization !== 'Bearer '.$this->token || str_contains($url, $this->token) || isset($query['access_token']) || isset($form['access_token'])) {
            return Http::response(['error' => ['message' => 'Invalid OAuth access token - Cannot parse access token', 'type' => 'OAuthException', 'code' => 190, 'fbtrace_id' => 'FakeMetaGraph']], 400);
        }
        if ($this->appRemoved) {
            return $this->answer('error_app_removed');
        }

        return $this->route($request->method(), $path);
    }

    private function route(string $method, string $path): mixed
    {
        $parts = explode('/', $path);
        $object = $parts[0];
        $edge = $parts[1] ?? null;
        $catalogs = [self::CATALOG_ID, self::GENERIC_CATALOG_ID, self::OTHER_CATALOG_ID];

        if ($object === 'me') {
            return match ($edge) {
                null => $this->answer('me'),
                'assigned_product_catalogs' => $this->assignedCatalogs(),
                default => $this->notFound(),
            };
        }
        if ($object === self::BUSINESS_ID && $edge === 'owned_product_catalogs') {
            return $this->answer($method === 'POST' ? 'error_create_catalog' : 'error_business_management');
        }
        if (in_array($object, $catalogs, true)) {
            if (isset($this->unshared[$object])) {
                return $this->answer($edge === 'items_batch' ? 'error_items_batch_catalog_unshared' : 'error_catalog_unshared');
            }

            return match (true) {
                $edge === null && $method === 'GET' => $this->answer($object === self::GENERIC_CATALOG_ID ? 'catalog_generic' : 'catalog', ['id' => $object]),
                $edge === 'product_feeds' && $method === 'GET' => $this->answer('product_feeds'),
                $edge === 'product_feeds' && $method === 'POST' => $this->answer('create_product_feed'),
                $edge === 'items_batch' && $method === 'POST' => $this->answer('items_batch'),
                $edge === 'check_batch_request_status' => $this->answer(match (true) {
                    $object === self::GENERIC_CATALOG_ID, $this->batchOutcome === self::BATCH_ERRORS => 'batch_status_errors',
                    $this->batchOutcome === self::BATCH_WARNINGS => 'batch_status_warnings',
                    default => 'batch_status',
                }),
                $edge === 'products' && $object === self::GENERIC_CATALOG_ID => $this->answer('error_generic_catalog_products'),
                default => $this->notFound(),
            };
        }
        if ($object === self::FEED_ID) {
            return match (true) {
                $edge === null && $method === 'GET' => $this->feed(),
                $edge === null && $method === 'POST' => $this->updateFeed(),
                $edge === null && $method === 'DELETE' => $this->answer('delete_product_feed'),
                $edge === 'uploads' && $method === 'POST' => $this->uploadsFail ? $this->answer('create_upload', ['id' => self::FAILED_UPLOAD_ID]) : $this->answer('create_upload'),
                default => $this->notFound(),
            };
        }
        if ($object === self::UPLOAD_ID || $object === self::FAILED_UPLOAD_ID) {
            $failed = $object === self::FAILED_UPLOAD_ID;

            return match ($edge) {
                null => $this->answer($failed ? 'upload_failed' : 'upload'),
                'errors' => $this->answer($failed ? 'upload_failed_errors' : 'upload_errors'),
                default => $this->notFound(),
            };
        }

        return $this->notFound();
    }

    private function assignedCatalogs(): mixed
    {
        $response = $this->response('assigned_product_catalogs');
        $response['body']['data'] = array_values(array_filter($response['body']['data'], fn (array $catalog): bool => ! isset($this->unshared[$catalog['id']])));

        return $this->toHttp($response);
    }

    private function feed(): mixed
    {
        $response = $this->response('product_feed');
        if ($this->feedUpdated) {
            $response['body']['schedule'] = $this->response('product_feed_after_update')['body']['schedule'];
        }

        return $this->toHttp($response);
    }

    private function updateFeed(): mixed
    {
        $this->feedUpdated = true;

        return $this->answer('update_product_feed');
    }

    /** Graph's answer to an unknown object or edge (same shape as the spike's 100/33). */
    private function notFound(): mixed
    {
        return $this->answer('error_catalog_unshared');
    }

    /** @param  array<string, mixed>  $override  top-level body keys replaced */
    private function answer(string $name, array $override = []): mixed
    {
        $response = $this->response($name);
        if ($override !== [] && is_array($response['body'])) {
            $response['body'] = array_replace($response['body'], $override);
        }

        return $this->toHttp($response);
    }

    /** @param  array{status: int, body: mixed, usage: string|null}  $response */
    private function toHttp(array $response): mixed
    {
        $headers = ['facebook-api-version' => (string) config('cms.meta.graph_version')];
        if ($response['usage'] !== null) {
            $headers[MetaUsage::HEADER] = $response['usage'];
        }

        return Http::response($response['body'], $response['status'], $headers);
    }
}
