<?php

namespace App\Connectors\Meta\Graph;

use App\Connectors\Meta\Connect\MetaConnectionStore;
use App\Contracts\Connectors\MetaGraphClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * MetaGraphClient over Laravel's HTTP client (no SDK): `{cms.meta.graph_url}/{cms.meta.graph_version}/…`, timeout
 * `cms.meta.timeout`, no retries. The token travels in the `Authorization: Bearer` header — never in the URL, so it
 * cannot reach an access log — and is removed from any error text. POST bodies are form-encoded with nested values as
 * JSON (schedule, requests), as the spike sent them. Ids are URL-encoded and must be Graph ids (digits) or `me`.
 */
final class HttpMetaGraphClient implements MetaGraphClient
{
    public const CATALOG_FIELDS = 'id,name,vertical,product_count';

    public const FEED_FIELDS = 'id,name,product_count,default_currency,schedule,latest_upload';

    /** @var list<MetaUsage> */
    private array $usage = [];

    public function __construct(
        private readonly MetaConnectionStore $store,
        #[SensitiveParameter] private readonly ?string $token = null,
    ) {}

    public function usingToken(#[SensitiveParameter] string $token): MetaGraphClient
    {
        return new self($this->store, $token);
    }

    public function me(): MetaIdentity
    {
        return MetaIdentity::fromGraph($this->get('me', ['fields' => 'id,name,client_business_id']));
    }

    public function assignedProductCatalogs(): array
    {
        $json = $this->get('me/assigned_product_catalogs', ['fields' => self::CATALOG_FIELDS]);

        return array_map(fn (array $catalog) => MetaCatalog::fromGraph($catalog), MetaGraphValues::data($json));
    }

    public function catalog(string $catalogId): MetaCatalog
    {
        return MetaCatalog::fromGraph($this->get(self::id($catalogId), ['fields' => self::CATALOG_FIELDS]));
    }

    public function productFeeds(string $catalogId): array
    {
        $json = $this->get(self::id($catalogId).'/product_feeds', ['fields' => self::FEED_FIELDS]);

        return array_map(fn (array $feed) => MetaProductFeed::fromGraph($feed), MetaGraphValues::data($json));
    }

    public function createProductFeed(string $catalogId, string $name, string $defaultCurrency, string $url, string $interval = 'HOURLY'): string
    {
        $json = $this->post(self::id($catalogId).'/product_feeds', [
            'name' => $name,
            'default_currency' => $defaultCurrency,
            'schedule' => ['interval' => $interval, 'url' => $url],
        ]);

        return $this->createdId($json);
    }

    public function productFeed(string $feedId): MetaProductFeed
    {
        return MetaProductFeed::fromGraph($this->get(self::id($feedId), ['fields' => self::FEED_FIELDS]));
    }

    public function updateProductFeedSchedule(string $feedId, string $url, string $interval = 'HOURLY'): void
    {
        $this->post(self::id($feedId), ['schedule' => ['interval' => $interval, 'url' => $url]]);
    }

    public function deleteProductFeed(string $feedId): void
    {
        $this->send('DELETE', self::id($feedId));
    }

    public function createUpload(string $feedId, string $url): string
    {
        return $this->createdId($this->post(self::id($feedId).'/uploads', ['url' => $url]));
    }

    public function upload(string $uploadId): MetaFeedUpload
    {
        return MetaFeedUpload::fromGraph($this->get(self::id($uploadId), ['fields' => MetaFeedUpload::FIELDS]));
    }

    public function uploadErrors(string $uploadId): array
    {
        $json = $this->get(self::id($uploadId).'/errors', ['fields' => MetaUploadError::FIELDS]);

        return array_map(fn (array $error) => MetaUploadError::fromGraph($error), MetaGraphValues::data($json));
    }

    public function itemsBatch(string $catalogId, array $requests, string $itemType = 'PRODUCT_ITEM'): array
    {
        if ($requests === [] || count($requests) > 5000) {
            throw new InvalidArgumentException('items_batch takes 1 to 5000 requests.');
        }
        $json = $this->post(self::id($catalogId).'/items_batch', ['item_type' => $itemType, 'requests' => array_values($requests)]);
        $handles = $json['handles'] ?? null;
        if (! is_array($handles) || $handles === []) {
            throw new MetaGraphException('Meta accepted items_batch without handles.', 200, 0);
        }

        return array_values(array_map('strval', $handles));
    }

    public function batchStatus(string $catalogId, string $handle): MetaBatchStatus
    {
        $json = $this->get(self::id($catalogId).'/check_batch_request_status', ['handle' => $handle, 'load_ids_of_invalid_requests' => 'true']);
        $data = MetaGraphValues::data($json);

        return MetaBatchStatus::fromGraph($data[0] ?? ['handle' => $handle]);
    }

    public function lastUsage(): array
    {
        return $this->usage;
    }

    /** Base URL of the configured version, without trailing slash. */
    public static function baseUrl(): string
    {
        return rtrim((string) config('cms.meta.graph_url', 'https://graph.facebook.com'), '/').'/'.config('cms.meta.graph_version', 'v27.0');
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private function post(string $path, array $form): array
    {
        $encoded = array_map(fn (mixed $value) => is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : $value, $form);

        return $this->send('POST', $path, $encoded);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $data = []): array
    {
        $token = $this->token ?? $this->store->accessToken();
        if ($token === null || $token === '') {
            throw MetaGraphException::notConnected();
        }
        $request = Http::acceptJson()
            ->withToken($token)
            ->timeout(max(1, (int) config('cms.meta.timeout', 20)))
            ->connectTimeout(max(1, (int) config('cms.meta.connection_timeout', 10)));
        $url = self::baseUrl().'/'.$path;

        try {
            /** @var Response $response */
            $response = match ($method) {
                'GET' => $request->get($url, $data),
                'DELETE' => $request->delete($url),
                default => $request->asForm()->post($url, $data),
            };
        } catch (ConnectionException) {
            $this->usage = [];
            throw MetaGraphException::connection('timeout or connection failure');
        }

        $this->usage = MetaUsage::fromHeader($response->header(MetaUsage::HEADER) ?: null);
        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if (! $response->successful() || isset($json['error'])) {
            throw MetaGraphException::fromResponse($response->status(), $json, $token, 'Meta answered HTTP '.$response->status());
        }

        return $json;
    }

    /** @param  array<string, mixed>  $json */
    private function createdId(array $json): string
    {
        $id = MetaGraphValues::string($json['id'] ?? null);
        if ($id === null) {
            throw new MetaGraphException('Meta answered without the id of the new object.', 200, 0);
        }

        return $id;
    }

    /** Graph object ids are digits; anything else is a programming error, never sent to Meta. */
    private static function id(string $id): string
    {
        if (preg_match('/^\d{1,32}$/', $id) !== 1) {
            throw new InvalidArgumentException('Not a Graph object id.');
        }

        return $id;
    }
}
