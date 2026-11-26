# RugerTek Connect (broker for Connect with Facebook)

> What the broker behind «Connect with Facebook» is, what a Ruweb site needs to use it, and a summary of the HTTP
> contract (v2) between the site and the broker.

**RugerTek Connect** is a small central service run by RugerTek, **a separate project from this repository**. Meta's
Facebook Login for Business needs one fixed redirect URI and the app secret to exchange the authorization code;
neither can live in every Ruweb installation. The broker:

- holds the Meta app credentials (the app secret exists only there) and is the single OAuth redirect URI;
- exchanges the code, reads the client's business id and the catalogs ticked in Meta's dialog;
- hands the token to the site that started the flow in one signed `POST`, server to server, **before** sending the
  browser back, and **never stores it** (it lives only in the memory of that request): the site is the only place
  that keeps it;
- keeps per site only its id, origin, name, the shared secret (encrypted), status and dates: no tokens and no
  catalog data. It never logs tokens, codes or secrets.

The broker is deployed as its own service (Azure App Service, separate from the RuWeb Cloud console); its README
covers deployment, Key Vault references for the Meta app secret and the health check.

What the site does with the token afterwards (feed, items_batch, health check): [connectors.md](connectors.md#connect-with-facebook-v2).

## Site configuration

| Variable | Meaning |
|---|---|
| `RUWEB_CONNECT_URL` | Base URL of the broker (`cms.meta.connect_url`), e.g. `https://connect.rugertek.com`. Must be `https://`. Empty: «Connect with Facebook» stays disabled and the manual feed is used. |
| `RUWEB_CONNECT_ALLOW_HTTP` | `true` accepts an `http://` broker URL, honoured only with `APP_ENV=local` or `testing` (development with a local broker and a tunnel). Keep `false` on real sites. |

Other settings live in `config/cms.php` under `meta` (`graph_version`, `graph_url`, `timeout`,
`connection_timeout`, `connect_timeout`). The site credentials the broker issues (site id + secret) are stored
encrypted in the `meta` connector row by `App\Connectors\Meta\Connect\MetaConnectionStore`, never in `.env`.

The broker must be able to reach the site's public origin (`APP_URL`) to verify it and to deliver the token, and the
site must answer the verification while it is itself waiting for the broker: with `php artisan serve` set
`PHP_CLI_SERVER_WORKERS=2` or more. A firewall, WAF or bot protection must let the broker's `POST` to
`/_ruweb/connect/meta/handoff` through.

## Contract v2 (summary)

JSON in UTF-8. Errors from the broker: `{"error": "<code>", "message": "..."}` with a 4xx/5xx status. Client in the
site: `App\Connectors\Meta\Connect\HttpRuwebConnectClient` (interface `App\Contracts\Connectors\RuwebConnectClient`);
receiver of the delivery: `App\Connectors\Meta\Connect\RuwebConnectHandoffReceiver`.

**Signatures** (`App\Connectors\Meta\Connect\RuwebConnectSignature`): the start link is signed by the site with
`sig = hex(hmac_sha256(site_secret, "start\n<site_id>\n<nonce>\n<ts>"))`. The token delivery is signed by the broker
with the same site secret: headers `X-Ruweb-Site: <site_id>`, `X-Ruweb-Timestamp: <unix>` and
`X-Ruweb-Signature: hex(hmac_sha256(site_secret, "POST\n/_ruweb/connect/meta/handoff\n<timestamp>\n<sha256_hex(body)>"))`;
the window is ±300 s, comparisons are constant-time and an identical signed request is accepted once. Shared test
vector (both repositories check it): secret `test-site-secret`, timestamp `1764000000`, body `{"nonce":"n"}` →
`d90ae0722d201b8b038496270737f8d72a88bd714756bfe9695259506a55bfc8`.

| # | Call | What happens |
|---|---|---|
| 1 | `POST /v1/sites/register` `{"origin", "name"}` | 201 `{registration_id, challenge_token, challenge_path, expires_in: 600}`. The site serves `challenge_token` as plain text at `/.well-known/ruweb-connect/<challenge_token>` (`App\Http\Controllers\RuwebConnectChallengeController`) while the registration lives. |
| 2 | `POST /v1/sites/register/{registration_id}/verify` | The broker GETs the challenge from the origin (HTTPS only in production) → 200 `{site_id, site_secret}`, the secret delivered once. Registering the same origin again rotates the secret. |
| 3 | Browser → `GET /v1/meta/start?site&nonce&ts&sig` | The site first keeps the nonce (session + `MetaConnectionStore::startFlow()`, 10 minutes). The broker redirects to Meta's dialog with a state it signs itself (site, nonce, 10-minute expiry). |
| 4 | Meta → `GET /v1/meta/callback` | The broker exchanges the code and reads the business id, the shared catalogs and the granted scopes. |
| 5 | Broker → site `POST /_ruweb/connect/meta/handoff` (signed) | Body `{access_token, token_type: "system_user", business_id, catalogs: [{id, name, vertical, product_count}], graph_version, granted_scopes, nonce}`. The site validates and keeps it for the administrator of that nonce → 200 `{"ok": true}`; any refusal → bare 404 `{"message": "Not Found"}` (`App\Http\Controllers\RuwebConnectHandoffController`). |
| 6 | Broker → browser `302 <origin>/admin/connectors/meta/connected?nonce=<nonce>` | Only after the 200. If the delivery failed the broker shows «Volvé a intentar» (a new flow from `/admin/connectors/meta/connect`) and nothing is kept. Cancelled dialog: `?error=cancelled&nonce=<nonce>` without a delivery. |
| 7 | `GET /v1/health` | 200 `{"ok": true, "version": "2"}`; 503 when the broker's database does not answer. |

v1's pickup (`POST /v1/meta/handoff/{handoff_id}` and the `?handoff=` parameter) no longer exists on either side.

Tests run the whole contract against `tests/Support/FakeRuwebConnect.php` (its `deliver()` signs the POST the way the
broker does), without a real broker.
