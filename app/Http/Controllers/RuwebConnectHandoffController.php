<?php

namespace App\Http\Controllers;

use App\Connectors\Meta\Connect\RuwebConnectHandoffReceiver;
use App\Connectors\Meta\Connect\RuwebConnectSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /_ruweb/connect/meta/handoff` (routes/connectors.php): RugerTek Connect delivers the Meta token here, server to
 * server, before sending the administrator's browser back (plan-meta-v2, contract v2). No session, cookies or CSRF:
 * the request is authenticated by its signature (RuwebConnectHandoffReceiver). 200 `{"ok": true}` when accepted; any
 * refusal is the same bare 404, so a caller learns nothing about why.
 */
class RuwebConnectHandoffController extends Controller
{
    public function __invoke(Request $request, RuwebConnectHandoffReceiver $receiver): JsonResponse
    {
        $accepted = $receiver->receive(
            $request->header(RuwebConnectSignature::HEADER_SITE),
            $request->header(RuwebConnectSignature::HEADER_TIMESTAMP),
            $request->header(RuwebConnectSignature::HEADER_SIGNATURE),
            (string) $request->getContent(),
        );

        return response()->json($accepted ? ['ok' => true] : ['message' => 'Not Found'], $accepted ? 200 : 404, [
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
