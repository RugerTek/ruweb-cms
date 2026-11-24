<?php

namespace App\Contracts\Connectors;

use App\Connectors\Meta\Graph\MetaBatchStatus;
use App\Connectors\Meta\Graph\MetaCatalog;
use App\Connectors\Meta\Graph\MetaFeedUpload;
use App\Connectors\Meta\Graph\MetaGraphException;
use App\Connectors\Meta\Graph\MetaIdentity;
use App\Connectors\Meta\Graph\MetaProductFeed;
use App\Connectors\Meta\Graph\MetaUploadError;
use App\Connectors\Meta\Graph\MetaUsage;
use SensitiveParameter;

/**
 * The Graph API calls of Meta v2 (plan-meta-v2, verified in the spike with a system user token that only has
 * `catalog_management`). Implemented by App\Connectors\Meta\Graph\HttpMetaGraphClient (bound in the container) and
 * replaced in tests by Tests\Support\FakeMetaGraph, which answers what Meta really answered.
 *
 * Calls use the token of App\Connectors\Meta\Connect\MetaConnectionStore unless usingToken() bound another one (2b
 * checks a token from the broker before saving it). Every failure throws MetaGraphException (code, subcode, fbtrace,
 * never the token); nothing is retried here: callers decide (190/102 → needs_reconnect, 100/33 → catalog unshared,
 * transient → later). Never call a catalog DELETE: the token can delete the client's catalog (spike §3.3.6).
 *
 * @throws MetaGraphException from every call (MetaGraphException::notConnected() without a usable token)
 */
interface MetaGraphClient
{
    /** A client bound to $token instead of the stored one (the broker handoff, before saving it). */
    public function usingToken(#[SensitiveParameter] string $token): self;

    /** `GET /me?fields=id,name,client_business_id`. */
    public function me(): MetaIdentity;

    /**
     * Catalogs the client shared in Meta's dialog: `GET /me/assigned_product_catalogs?fields=id,name,vertical,product_count`.
     *
     * @return list<MetaCatalog>
     */
    public function assignedProductCatalogs(): array;

    /** `GET /{catalog}?fields=id,name,vertical,product_count` (100/33 when it is no longer shared). */
    public function catalog(string $catalogId): MetaCatalog;

    /**
     * `GET /{catalog}/product_feeds?fields=id,name,schedule,latest_upload,product_count,default_currency`.
     *
     * @return list<MetaProductFeed>
     */
    public function productFeeds(string $catalogId): array;

    /**
     * `POST /{catalog}/product_feeds` name, default_currency, schedule {interval, url}: a scheduled (replace) feed.
     * Returns the feed id.
     */
    public function createProductFeed(string $catalogId, string $name, string $defaultCurrency, string $url, string $interval = 'HOURLY'): string;

    /** `GET /{feed}?fields=id,name,product_count,default_currency,schedule,latest_upload`. */
    public function productFeed(string $feedId): MetaProductFeed;

    /** `POST /{feed}` schedule {interval, url}: a new feed URL (token regenerated); the schedule id stays. */
    public function updateProductFeedSchedule(string $feedId, string $url, string $interval = 'HOURLY'): void;

    /** `DELETE /{feed}` (disconnecting). The catalog itself is never deleted. */
    public function deleteProductFeed(string $feedId): void;

    /** `POST /{feed}/uploads` url: fetch the feed now. Returns the upload id. */
    public function createUpload(string $feedId, string $url): string;

    /** `GET /{upload}?fields=…` (MetaFeedUpload::FIELDS). */
    public function upload(string $uploadId): MetaFeedUpload;

    /**
     * `GET /{upload}/errors?fields=…` (MetaUploadError::FIELDS).
     *
     * @return list<MetaUploadError>
     */
    public function uploadErrors(string $uploadId): array;

    /**
     * `POST /{catalog}/items_batch` item_type, requests (≤ 5000 per call; `/batch` is gone in v27). Each request is
     * `['method' => 'CREATE'|'UPDATE'|'DELETE', 'data' => ['id' => <retailer id>, …feed fields]]`. Returns the handles.
     *
     * @param  list<array{method: string, data: array<string, mixed>}>  $requests
     * @return list<string>
     */
    public function itemsBatch(string $catalogId, array $requests, string $itemType = 'PRODUCT_ITEM'): array;

    /** `GET /{catalog}/check_batch_request_status?handle=…&load_ids_of_invalid_requests=true`. */
    public function batchStatus(string $catalogId, string $handle): MetaBatchStatus;

    /**
     * `x-business-use-case-usage` of the last answer (success or error); empty before any call.
     *
     * @return list<MetaUsage>
     */
    public function lastUsage(): array;
}
