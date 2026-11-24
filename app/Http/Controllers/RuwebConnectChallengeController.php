<?php

namespace App\Http\Controllers;

use App\Connectors\Meta\Connect\MetaConnectionStore;
use Illuminate\Http\Response;

/**
 * `GET /.well-known/ruweb-connect/{token}` (routes/connectors.php): proves to the RugerTek Connect broker that this site
 * controls its origin while a registration is in progress (plan-meta-v2, contract step 1). Answers the challenge
 * token as text/plain only when {token} is the one MetaConnectionStore::startRegistration() saved and it has not
 * expired; anything else is a 404 that says nothing.
 */
class RuwebConnectChallengeController extends Controller
{
    public function __invoke(string $token, MetaConnectionStore $store): Response
    {
        $challenge = $store->challengeFor($token);
        abort_if($challenge === null, 404);

        return response($challenge, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
