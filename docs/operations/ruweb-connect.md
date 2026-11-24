# RugerTek Connect (broker for Connect with Facebook)

> What the broker behind «Connect with Facebook» is, what a Ruweb site needs to use it, and a summary of the HTTP
> contract (v1) between the site and the broker.

**RugerTek Connect** is a small central service run by RugerTek, **a separate project from this repository**. Meta's
Facebook Login for Business needs one fixed redirect URI and the app secret to exchange the authorization code;
neither can live in every Ruweb installation. The broker:

- holds the Meta app credentials (the app secret exists only there) and is the single OAuth redirect URI;
- exchanges the code, reads the client's business id and the catalogs ticked in Meta's dialog;
- hands the token to the site that started the flow **once**, server to server, and wipes its copy (a pending
  handoff lives at most 10 minutes);
- keeps per site only its id, origin, name, the shared secret (encrypted), status and dates: no catalog data and no
  long-lived tokens. It never logs tokens, codes or secrets.

What the site does with the token afterwards (feed, items_batch, health check): [connectors.md](connectors.md#connect-with-facebook-v2).

## Site configuration

| Variable | Meaning |
|---|---|
| `RUWEB_CONNECT_URL` | Base URL of the broker (`cms.meta.connect_url`), e.g. `https://connect.rugertek.com`. Must be `https://`. Empty: «Connect with Facebook» stays disabled and the manual feed is used. |
| `RUWEB_CONNECT_ALLOW_HTTP` | `true` accepts an `http://` broker URL, honoured only with `APP_ENV=local` or `testing` (development with a local broker and a tunnel). Keep `false` on real sites. |

Other settings live in `config/cms.php` under `meta` (`graph_version`, `graph_url`, `timeout`,
`connection_timeout`, `connect_timeout`). The site credentials the broker issues (site id + secret) are stored
encrypted in the `meta` connector row by `App\Connectors\Meta\Connect\MetaConnectionStore`, never in `.env`.

The broker must be able to reach the site's public origin (`APP_URL`) to verify it, and the site must answer that
request while it is itself waiting for the broker: with `php artisan serve` set `PHP_CLI_SERVER_WORKERS=2` or more.

## Contract v1 (summary)

JSON in UTF-8. Errors: `{"error": "<code>", "message": "..."}` with a 4xx/5xx status. Client in the site:
`App\Connectors\Meta\Connect\HttpRuwebConnectClient` (interface `App\Contracts\Connectors\RuwebConnectClient`).

**Signed requests (site → broker)**: headers `X-Ruweb-Site: <site_id>`, `X-Ruweb-Timestamp: <unix>` and
`X-Ruweb-Signature: hex(hmac_sha256(site_secret, "<METHOD>\n<path>\n<timestamp>\n<sha256_hex(body)>"))`; the path
has no query string, the window is ±300 s, comparisons are constant-time and each attempt is signed again
(`App\Connectors\Meta\Connect\RuwebConnectSignature`).

| # | Call | What happens |
|---|---|---|
| 1 | `POST /v1/sites/register` `{"origin", "name"}` | 201 `{registration_id, challenge_token, challenge_path, expires_in: 600}`. The site serves `challenge_token` as plain text at `/.well-known/ruweb-connect/<challenge_token>` (`App\Http\Controllers\RuwebConnectChallengeController`) while the registration lives. |
| 2 | `POST /v1/sites/register/{registration_id}/verify` | The broker GETs the challenge from the origin (HTTPS only in production) → 200 `{site_id, site_secret}`, the secret delivered once. Registering the same origin again rotates the secret. |
| 3 | Browser → `GET /v1/meta/start?site&nonce&ts&sig` | `sig = hex(hmac_sha256(site_secret, "start\n<site_id>\n<nonce>\n<ts>"))`. The broker redirects to Meta's dialog with a state it signs itself (site, nonce, 10-minute expiry). |
| 4 | Meta → `GET /v1/meta/callback` | The broker exchanges the code, reads the business id and the shared catalogs, keeps a one-time handoff and redirects to `<origin>/admin/connectors/meta/connected?handoff=<id>&nonce=<nonce>` (or `?error=cancelled&nonce=<nonce>` when the client cancels). |
| 5 | `POST /v1/meta/handoff/{handoff_id}` (signed) | 200 `{access_token, token_type: "system_user", business_id, catalogs: [{id, name, vertical, product_count}], graph_version, granted_scopes}` and the broker deletes it; a second call is 404 `handoff_not_found`. |
| 6 | `GET /v1/health` | 200 `{"ok": true, "version": "1"}`. |

Tests run the whole contract against `tests/Support/FakeRuwebConnect.php`, without a real broker.
