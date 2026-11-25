# RügerTek Support Access

RügerTek operators enter the admin of any Ruweb site from the RuWeb Cloud console, without a per-site login and
without asking the client for anything. Access is total (an administrator account), and **everything is recorded**:
who entered, when, for how long, every screen they opened and every change they made. The client sees that record
in Admin > Actividad de soporte. There is deliberately no switch to turn support access off from the panel: the
visible record is the transparency.

| Piece | Where |
|---|---|
| Entry route | `routes/support.php` → `App\Http\Controllers\SupportAccessController` |
| Pass verification | `app/Services/SupportAccess/SupportPassVerifier.php`, keys in `app/Services/SupportAccess/SupportAccessKeys.php` |
| Support account and session | `app/Services/SupportAccess/SupportSessions.php`, `app/Http/Middleware/EnforceSupportSession.php` |
| Activity record | `app/Http/Middleware/RecordSupportActivity.php`, `app/Services/SupportAccess/SupportActivityRecorder.php` |
| Activity screen | `app/Http/Controllers/Admin/SupportActivityController.php`, `resources/views/admin/support/index.blade.php` |
| MCP | `support_activity_list` ([mcp.md](mcp.md#support-activity)) |
| Commands | `support:keypair`, `support:issue-pass`, `support:prune` |
| Tests | `tests/Feature/SupportAccessTest.php` |

## Flow

1. The operator clicks «Entrar al admin» for a site in the RuWeb Cloud console.
2. The console signs a **pass** with its Ed25519 PRIVATE key and opens
   `https://<site>/_ruweb/support/enter?t=<pass>` in the operator's browser.
3. The site verifies the pass with the console's PUBLIC key (the only key it holds), marks its `jti` as used, finds or
   creates the operator's support account, starts a support session and redirects to `/admin`.
4. The operator works in the admin with a fixed bar on top: «Modo soporte RügerTek — <name> — vence 17:40 — Salir del
   modo soporte». Only the operator sees it.
5. The session ends at its expiry whatever the activity, on «Salir del modo soporte» or on logout. After that the
   operator needs a new pass.

Every rejection (bad signature, other site, expired, reused, foreign email, malformed, feature off) answers the same
plain 404. The log gets `Support pass rejected.` with a reason code and the IP, never the pass. The 404 is rendered,
not thrown, so the 404 monitor ([redirects.md](redirects.md)) never stores the URL. The route is throttled to
10 attempts per minute per IP. Responses carry `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.

The pass travels in a GET URL, so it can appear in the web server's access log. That is acceptable because it lives at
most 120 seconds and works once.

## Pass Format

`<payload>.<signature>`. Both parts are base64url without padding.

- `payload` is the UTF-8 JSON of the claims below.
- `signature` is the 64-byte Ed25519 detached signature (libsodium `crypto_sign_detached`) of the ASCII string
  `"ruweb-support-pass.v1\n" + <payload segment exactly as sent>`. The context prefix keeps a console signature over
  anything else from ever being accepted as a pass. Because the payload is signed as encoded, no JSON canonicalization
  is needed.

| Claim | Type | Rule |
|---|---|---|
| `v` | int | `1` |
| `aud` | string | Exact origin of the site, `scheme://host[:port]` of its `APP_URL` (default ports omitted, lowercase), e.g. `https://www.cliente.com.py` |
| `iss` | string | `RUWEB_SUPPORT_ISSUER` (default `ruweb-cloud`) |
| `sub` | string | Operator email; its domain must be exactly `RUWEB_SUPPORT_OPERATOR_DOMAIN` (default `rugertek.com`, no subdomains) |
| `name` | string | Operator display name, 1–80 characters, no control characters |
| `role` | string | `operacion` or `desarrollo` |
| `iat` | int | Unix seconds when issued |
| `exp` | int | Unix seconds; `0 < exp - iat ≤ 120` |
| `jti` | string | 16–128 characters `[A-Za-z0-9_-]`, unique per pass (random) |
| `session_ttl` | int, optional | Support session length in seconds, 60–14400 (4 h); default 7200 (2 h) |

Any other claim, a missing claim or a wrong type rejects the pass: a new field means a new `v`. Clock tolerance is
±60 seconds (`iat ≤ now + 60` and `exp ≥ now − 60`). Audience and issuer are compared in constant time, and every
configured public key is tried, without stopping at the first match.

Example payload:

```json
{"v":1,"aud":"https://www.cliente.com.py","iss":"ruweb-cloud","sub":"ana@rugertek.com","name":"Ana","role":"operacion","iat":1795600000,"exp":1795600060,"jti":"Q2hhbmdlLW1lLXJhbmRvbS0xMjM0NTY3OA","session_ttl":7200}
```

## Variables

| Variable | Meaning |
|---|---|
| `RUWEB_SUPPORT_PUBLIC_KEYS` | Base64 Ed25519 public key(s) of the console, comma separated. Only the first two are used. Empty or no valid key: the feature is off and the entry answers 404. |
| `RUWEB_SUPPORT_ISSUER` | Expected `iss`; default `ruweb-cloud`. |
| `RUWEB_SUPPORT_OPERATOR_DOMAIN` | Domain of operator emails; default `rugertek.com`. |
| `RUWEB_SUPPORT_RETENTION_DAYS` | Days the record is kept; default 365. |
| `RUWEB_SUPPORT_DEV_PRIVATE_KEY` | Development only: signing key for `support:issue-pass`. Ignored unless `APP_ENV` is `local` or `testing`. Never set it on a real site. |

They are read in `config/cms.php` under `support_access`, together with the fixed limits (`max_pass_lifetime` 120,
`clock_skew` 60, `default_session_ttl` 7200, `max_session_ttl` 14400). Run `php artisan config:clear` (or
`config:cache`) after changing them on a server with cached config.

A site holding only public keys cannot forge passes, for itself or for any other site. A leaked site therefore puts no
other site at risk.

## Key Rotation

1. The console generates the new pair and publishes its public key.
2. Each site gets `RUWEB_SUPPORT_PUBLIC_KEYS=<current>,<next>`. Both verify.
3. The console starts signing with the new key.
4. When every site has the new key, remove the old one: `RUWEB_SUPPORT_PUBLIC_KEYS=<next>`.

To revoke a compromised console key immediately, remove it from every site. Passes signed with it stop working at once.
A third key in the list is ignored, so a rotation never widens to more than two trusted keys.

## Support Accounts

The first entry of an operator creates a user with `is_support = true`, role `admin`, the operator's email, the name
«Soporte RügerTek – <name>» and a random password nobody knows. Later entries reuse it and refresh the name. Because it
is a real user, every existing history (content revisions, operation actors, connector «connected by», editorial
reviews) already says which operator did what.

- `role: desarrollo` also gets `can_manage_custom_code` ([custom-code.md](custom-code.md)); `operacion` does not.
- It cannot sign in through the login form (`LoginRequest` excludes support accounts), never gets a password reset
  link, and its MCP keys are refused (`AuthenticateMcpApiKey`).
- During support it cannot create credentials that would outlive the session: changing its own password or email,
  two-factor setup and MCP key generation answer 403, and the attempt is recorded.
- It is hidden from the client's user management (not listed, not counted, edit and delete answer 404), from
  reviewer and submission assignee lists, and from site emails to administrators (`User::clientAccounts()`).
- If the operator's email already belongs to an ordinary user of the site, that user is **never** reused or
  converted. The entry answers 409 with a clear message, and no session starts.

Support accounts are never deleted automatically, so their names keep the history readable after retention.

## Support Sessions

The Laravel session keeps only `support_session_id`. `EnforceSupportSession` runs on every admin request of a support
account. It needs that id to point at an open `support_sessions` row of the same user, before `expires_at`. Otherwise
it signs the operator out. An expired session is closed with `end_reason = expired` and the page says the support
session ended. A support account without a support session gets a 404. Activity does not extend the session.

`end_reason`: `exit` («Salir del modo soporte», `POST /admin/support/exit`), `logout`, `expired`, `replaced` (a new pass
opened in the same browser).

## What Is Recorded

`support_sessions`: `user_id`, `operator_email`, `operator_name`, `role`, `issuer`, `jti`, `ip`, `user_agent`,
`started_at`, `expires_at`, `ended_at`, `end_reason`.

`support_audit`, one row per request during a support session:

- every admin request that is not GET/HEAD/OPTIONS;
- every page view (a full GET; the editor's background JSON/AJAX calls are not counted as views).

Each row stores `at`, `method`, `route_name`, `path` (**without the query string**), the subject the route points at
(`subject_type`, `subject_id`, and `subject_label`: the record's title or name read before the action, so a deleted
record keeps its name), `changed_fields` and the response `status` (403 for a blocked credential attempt, 500 if it
crashed).

`changed_fields` holds the **names** of the submitted fields in dot notation (`settings.site_name`,
`variants.*.price`; lists collapse to `*`, depth 3, at most 60 names), uploaded file fields included. For settings
these are the setting keys. **No submitted value is ever stored**, sensitive or not: passwords, tokens, secrets, keys
and card data are recorded only by name, like every other field. The pass itself is never stored or logged; only its
`jti` is kept.

`support_pass_jtis` keeps used `jti`s until the pass could no longer be valid, so a pass works once even if the first
attempt failed later.

## Retention

`support:prune` runs daily (`routes/console.php`). It deletes sessions that started more than
`RUWEB_SUPPORT_RETENTION_DAYS` ago (365 by default) with their activity, and forgets expired `jti`s.

## Activity Screen

Admin > Actividad de soporte (`/admin/support-activity`, administrators only, the client's included; editors get 403)
lists every session: operator, email, role, when, state, duration, number of changes and page views, and the first
changes as readable lines («Cambió los ajustes del sitio», «Editó el producto «Remera»», «Borró el usuario «Pedro»»;
built by `SupportActivitySummary` from the route and subject only). The detail page shows the session data (IP,
browser, issuer, how it ended) and every entry with its submitted field names and status. The same data is available
read-only to agents through `support_activity_list`.

## Try It Locally

```bash
php artisan support:keypair            # prints RUWEB_SUPPORT_PUBLIC_KEYS=… and RUWEB_SUPPORT_DEV_PRIVATE_KEY=…
# paste both lines into .env (APP_ENV=local, APP_URL=http://localhost:8000), then:
php artisan config:clear
php artisan migrate
php artisan support:issue-pass ana@rugertek.com --name=Ana --ttl=900 --role=operacion
```

`support:issue-pass` prints a single-use URL valid for 60 seconds; open it in the browser. Both commands refuse to run
unless `APP_ENV` is `local` or `testing`, and `support:issue-pass` also needs `RUWEB_SUPPORT_DEV_PRIVATE_KEY`. Real
passes are issued only by the console.

## What RuWeb Cloud Still Needs

The console side is a separate change in `RugerTek/ruweb-cloud`, agreed with its maintainer:

- Generate the production Ed25519 pair. The private key is kept only in the console's secret store, and the public
  key is distributed to every site as `RUWEB_SUPPORT_PUBLIC_KEYS`.
- An «Entrar al admin» button per site. It is only for authenticated RügerTek operators, whose email domain matches
  `RUWEB_SUPPORT_OPERATOR_DOMAIN`. It builds the payload of [Pass Format](#pass-format) with `aud` = the
  site's `APP_URL` origin, a random `jti`, `exp = iat + 60`, the operator's `role` and an optional `session_ttl`. It
  signs `"ruweb-support-pass.v1\n" + payload` and opens the URL in a new tab, without logging the pass.
- Optionally, its own log of issued passes (operator, site, `jti`, time), to cross-check with each site's record.
- The rotation procedure above, and an updated list of sites with their `APP_URL` origin.

The hidden agent package is not involved. Support access needs nothing from it.
