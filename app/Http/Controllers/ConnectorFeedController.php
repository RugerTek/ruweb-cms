<?php

namespace App\Http\Controllers;

use App\Connectors\ConnectorRegistry;
use App\Services\Connectors\ConnectorOperations;
use App\Services\Connectors\FeedFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public feed of a connector, `GET /feeds/{connector}/{token}.xml` (route `connectors.feed`, PLAN.md §4.7). 404 for
 * an unknown, never activated or disabled connector, a wrong or rotated token, or an unmet requirement (shop off),
 * so a guess confirms nothing. Serves the generated file (App\Services\Connectors\FeedFile), never XML built per
 * request, with an ETag (matching If-None-Match → 304); every answer with a valid token is logged through
 * ConnectorOperations::recordFetch(). Compression is left to the web server (docs/operations/connectors.md).
 */
class ConnectorFeedController extends Controller
{
    public function show(
        Request $request,
        string $connector,
        string $token,
        ConnectorRegistry $registry,
        ConnectorOperations $operations,
        FeedFile $files,
    ): Response|StreamedResponse {
        $record = $registry->feedRecord($connector, $token);
        abort_if($record === null, 404);

        $path = $files->fresh($record);
        clearstatcache(true, $path);
        $etag = '"'.hash_file('xxh128', $path).'"';
        $headers = [
            'Content-Type' => $registry->feed($connector)->contentType(),
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age='.ConnectorOperations::FEED_TTL_SECONDS,
            'Last-Modified' => gmdate('D, d M Y H:i:s', (int) filemtime($path)).' GMT',
            // The URL carries a secret: search engines must not list it.
            'X-Robots-Tag' => 'noindex, nofollow',
        ];

        $operations->recordFetch($record, $request->userAgent());

        if ($this->matches($request, $etag)) {
            return response('', 304, $headers);
        }

        // The whole file every time: a Range header is ignored so a platform never stores half a catalog.
        return response()->stream(function () use ($path) {
            $handle = fopen($path, 'rb');
            if ($handle !== false) {
                fpassthru($handle);
                fclose($handle);
            }
        }, 200, $headers + ['Content-Length' => (string) filesize($path)]);
    }

    private function matches(Request $request, string $etag): bool
    {
        foreach ($request->getETags() as $candidate) {
            if ($candidate === '*' || preg_replace('#^W/#', '', $candidate) === $etag) {
                return true;
            }
        }

        return false;
    }
}
