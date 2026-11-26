# RügerTek Support Access

RügerTek operators enter the admin of a Ruweb site from the RuWeb Cloud console, without a per-site login. Access is
total (an administrator account), always with a stated **reason**, and **everything is recorded**: who entered, when,
why, for how long, every screen they opened and every change they made. The client sees it twice: a notice on the
admin home («Soporte RügerTek (Ana) entró hoy a las 15:20 — Motivo: …») and the full record in Admin > Actividad de
soporte. **Each site can turn support access off** from that screen.

The model is Amiba's support session (mandatory reason, sessions that expire by themselves, a switch that closes open
sessions on their next request), adapted to a console that signs passes with a key it never sees: the private key is
created by RugerTek Cybersecurity in Azure Key Vault, not exportable, and the console signs through Key Vault.

| Piece | Where |
|---|---|
| Entry route | `routes/support.php` → `App\Http\Controllers\SupportAccessController` |
| Pass verification | `app/Services/SupportAccess/SupportPassVerifier.php`, keys in `app/Services/SupportAccess/SupportAccessKeys.php`, signature encoding in `app/Services/SupportAccess/Es256Signature.php` |
| Per-site switch | `app/Services/SupportAccess/SupportAccessSwitch.php` |
| Support account and session | `app/Services/SupportAccess/SupportSessions.php`, `app/Http/Middleware/EnforceSupportSession.php` |
| Activity record | `app/Http/Middleware/RecordSupportActivity.php`, `app/Services/SupportAccess/SupportActivityRecorder.php` |
| Activity screen and switch | `app/Http/Controllers/Admin/SupportActivityController.php`, `resources/views/admin/support/index.blade.php` |
| Home notice | `app/Services/SupportAccess/SupportNotices.php`, `resources/views/admin/support/notice.blade.php` |
| MCP | `support_activity_list` ([mcp.md](mcp.md#support-activity)) |
| Commands | `support:keypair`, `support:issue-pass`, `support:prune` |
| Tests | `tests/Feature/SupportAccessTest.php` |

## Flow

1. The operator clicks «Entrar al admin» for a site in the RuWeb Cloud console and writes the reason (10–500
   characters; optionally a ticket reference).
2. The console builds the pass payload, asks Azure Key Vault to sign it (ES256) and opens
   `https://<site>/_ruweb/support/enter?t=<pass>` in the operator's browser. The pass expires in at most 30 seconds.
3. The site checks that support access is allowed on it, verifies the pass with the console's PUBLIC key (the only key
   it holds), marks its `jti` as used, finds or creates the operator's support account, starts a support session
   (reason and ticket included) and redirects to `/admin`.
4. The operator works in the admin with a fixed bar on top: «Modo soporte RügerTek — <name> — vence 17:40 — Salir del
   modo soporte». Only the operator sees it.
5. The session ends at its expiry whatever the activity, on «Salir del modo soporte», on logout, or as soon as the
   client turns support access off. After that the operator needs a new pass.

Every rejection (support access turned off on the site, bad signature, other site, expired, reused, foreign email,
missing or invalid reason, malformed, feature off) answers the same plain 404. The log gets `Support pass rejected.`
with a reason code (`switched_off`, `signature`, `reason`, …) and the IP, never the pass. The 404 is rendered, not
thrown, so the 404 monitor ([redirects.md](redirects.md)) never stores the URL. The route is throttled to 10 attempts
per minute per IP. Responses carry `Cache-Control: no-store` and `Referrer-Policy: no-referrer`.

The pass travels in a GET URL, so it can appear in the web server's access log. That is acceptable because it lives
seconds and works once.

## Pass Format

`<payload>.<signature>`. Both parts are base64url without padding.

- `payload` is the UTF-8 JSON of the claims below.
- `signature` is described in [Signature](#signature). The signed message is the ASCII string
  `"ruweb-support-pass.v2\n" + <payload segment exactly as sent>`. The context prefix keeps a console signature over
  anything else from ever being accepted as a pass. Because the payload is signed as encoded, no JSON canonicalization
  is needed.

| Claim | Type | Rule |
|---|---|---|
| `v` | int | `2` (v1 passes, Ed25519, are no longer accepted) |
| `aud` | string | Exact origin of the site, `scheme://host[:port]` of its `APP_URL` (default ports omitted, lowercase), e.g. `https://www.cliente.com.py` |
| `iss` | string | `RUWEB_SUPPORT_ISSUER` (default `ruweb-cloud`) |
| `sub` | string | Operator email; its domain must be exactly `RUWEB_SUPPORT_OPERATOR_DOMAIN` (default `rugertek.com`, no subdomains) |
| `name` | string | Operator display name, 1–80 characters, no control characters, no `<` or `>` |
| `role` | string | `operacion` or `desarrollo` |
| `reason` | string | **Required.** Why the operator enters, shown to the client. Plain text: after collapsing whitespace runs (line breaks included) to one space, 10–500 characters, no `<` or `>`, no control or invisible formatting characters |
| `ticket` | string, optional | Short reference of the request: 1–64 characters, letters, digits and `# . _ : / -`, starting with a letter or digit (e.g. `TK-1234`) |
| `iat` | int | Unix seconds when issued |
| `exp` | int | Unix seconds; `0 < exp - iat ≤ 30` |
| `jti` | string | 16–128 characters `[A-Za-z0-9_-]`, unique per pass (random) |
| `session_ttl` | int, optional | Support session length in seconds, 60–14400 (4 h); default 7200 (2 h) |

Any other claim, a missing claim or a wrong type rejects the pass: a new field means a new `v`. Audience and issuer
are compared in constant time, and every configured public key is tried, without stopping at the first match.

**Clock tolerance is ±30 seconds**: `iat ≤ now + 30` and `exp ≥ now − 30`. With the 30-second lifetime a pass is
therefore never accepted more than 60 seconds after the console issued it, even with clocks 30 seconds apart. The
console (Azure) and the sites keep NTP time, so 30 seconds is generous; widening it only makes a leaked URL usable
for longer.

Example payload:

```json
{"v":2,"aud":"https://www.cliente.com.py","iss":"ruweb-cloud","sub":"ana@rugertek.com","name":"Ana","role":"operacion","reason":"Revisar por qué no sincroniza el catálogo de Meta","ticket":"TK-1234","iat":1795600000,"exp":1795600030,"jti":"Q2hhbmdlLW1lLXJhbmRvbS0xMjM0NTY3OA","session_ttl":7200}
```

## Signature

ES256: ECDSA on the P-256 curve with SHA-256 ([RFC 7518 §3.4](https://www.rfc-editor.org/rfc/rfc7518#section-3.4)).

- The console computes `digest = SHA-256(message)` over the message above and calls Key Vault `sign` with
  `alg = ES256` and that digest. Key Vault returns the signature as **r‖s: 64 bytes** (32-byte big-endian `r`
  followed by 32-byte `s`), base64url, which is exactly the JWS form. That value goes after the dot, as is.
- The site decodes the 64 bytes, converts them to the ASN.1 DER `SEQUENCE { INTEGER r, INTEGER s }` that OpenSSL
  expects (`Es256Signature::rawToDer()`) and checks it with `openssl_verify(message, der, key, OPENSSL_ALGO_SHA256)`.
- A DER signature sent instead of r‖s is rejected (the signature segment must be exactly 86 base64url characters).

Azure Key Vault (Standard, Premium and Managed HSM) signs with ES256 but not with Ed25519, which is why v2 replaced
the Ed25519 passes of v1 ([key types and algorithms](https://learn.microsoft.com/en-us/azure/key-vault/keys/about-keys-details)).

## Keys

`RUWEB_SUPPORT_PUBLIC_KEYS` holds the console's P-256 PUBLIC key, or two during a rotation, comma separated. Each
entry is one of:

- **JWK** (what Key Vault exports): a JSON object with `"kty": "EC"` (or `"EC-HSM"`), `"crv": "P-256"`, and `x`, `y`
  base64url of 32 bytes each. Other members (`kid`, `key_ops`) are ignored; a JWK with a private part (`d`) is
  refused. Put the whole value in single quotes in `.env` because it has double quotes and commas:
  `RUWEB_SUPPORT_PUBLIC_KEYS='{"kty":"EC","crv":"P-256","x":"…","y":"…"}'`.
- **PEM SubjectPublicKeyInfo**: the armored `-----BEGIN PUBLIC KEY-----` block (with real or `\n` line breaks), or
  just its base64 body on one line.

Commas inside a JWK do not split entries. Only the first two entries are used; an entry that is not a valid P-256
public key (an RSA key, another curve, a point off the curve, a v1 Ed25519 key, garbage) is ignored. With no valid key
the feature is off and the entry answers 404.

Getting the public key out of Key Vault (the private key never leaves it):

```bash
# JWK, one line (needs jq): paste it between single quotes in RUWEB_SUPPORT_PUBLIC_KEYS
az keyvault key show --vault-name <vault> --name ruweb-support-pass --query key -o json | jq -c '{kty: "EC", crv, x, y}'

# or the PEM public key; paste the base64 lines joined into one line (or keep the armored block with \n)
az keyvault key download --vault-name <vault> --name ruweb-support-pass --encoding PEM --file ruweb-support-pass.pub.pem
```

The key is created by Cybersecurity, for example
`az keyvault key create --vault-name <vault> --name ruweb-support-pass --kty EC-HSM --curve P-256 --ops sign verify`
(`--kty EC` on a Standard vault). It is not exportable: do not pass `--exportable`.

A site holding only public keys cannot forge passes, for itself or for any other site. A leaked site therefore puts no
other site at risk.

## Variables

| Variable | Meaning |
|---|---|
| `RUWEB_SUPPORT_PUBLIC_KEYS` | The console's P-256 public key(s), see [Keys](#keys). Empty or no valid key: the feature is off and the entry answers 404. |
| `RUWEB_SUPPORT_ISSUER` | Expected `iss`; default `ruweb-cloud`. |
| `RUWEB_SUPPORT_OPERATOR_DOMAIN` | Domain of operator emails; default `rugertek.com`. |
| `RUWEB_SUPPORT_RETENTION_DAYS` | Days the record is kept; default 365. |
| `RUWEB_SUPPORT_DEV_PRIVATE_KEY` | Development only: P-256 signing key (PKCS#8 PEM body) for `support:issue-pass`. Ignored unless `APP_ENV` is `local` or `testing`. Never set it on a real site. |

They are read in `config/cms.php` under `support_access`, together with the fixed limits (`max_pass_lifetime` 30,
`clock_skew` 30, `default_session_ttl` 7200, `max_session_ttl` 14400). Run `php artisan config:clear` (or
`config:cache`) after changing them on a server with cached config.

## Key Rotation

1. Cybersecurity creates the new key in Key Vault (or a new version of it) and exports its public part.
2. Each site gets `RUWEB_SUPPORT_PUBLIC_KEYS=<current>,<next>`. Both verify.
3. The console starts signing with the new key.
4. When every site has the new key, remove the old one: `RUWEB_SUPPORT_PUBLIC_KEYS=<next>`.

To revoke a compromised console key immediately, remove it from every site (and disable it in Key Vault). Passes
signed with it stop working at once. A third key in the list is ignored, so a rotation never widens to more than two
trusted keys.

## Turning It Off

Admin > Actividad de soporte shows the switch «Permitir acceso de soporte de RügerTek», **on by default**.

- Only the client's own administrators can change it, after a confirmation. Support accounts are administrators too,
  but they see it read-only («Solo un administrador del sitio puede cambiar esto; el equipo de soporte no.») and the
  route answers 403 (the attempt is recorded). Editors get 403.
- Every change is a row of `support_access_changes`: `allowed`, `user_id`, `user_name`, `ip`, `changed_at`. The
  screen shows the last one («Lo desactivó Laura el 26/11/2026 15:20»).
- Off: the entry answers the same 404 as any rejection (the pass is not even read; log code `switched_off`), every
  open support session is closed at once with `end_reason = disabled`, and `EnforceSupportSession` also checks the
  switch on every request of a support account, so a session still open in a browser ends on its next request with
  the page «La sesión de soporte terminó — El cliente desactivó el acceso de soporte…».
- On again: new passes work; closed sessions stay closed.

The switch is not a setting: `settings_update`, the settings screen and the installer cannot change it, and there is
no MCP tool that changes it (support accounts cannot use MCP keys, but an MCP write tool would still be a second way
in). `support_activity_list` reports it read-only in `meta.access`.

## Client Notice

The admin home (`resources/views/admin/dashboard.blade.php` includes `admin.support.notice`) shows the client's
administrators a notice for each support entry of the last 7 days:

> Soporte RügerTek (Ana) entró hoy a las 15:20 — Motivo: Revisar por qué no sincroniza el catálogo de Meta (Ticket TK-1234)
> Ver actividad de soporte · Entendido

«hoy», «ayer» or the date, in the site's time zone. With more than one entry it adds «Y N ingresos más en los últimos
7 días» and links to the full list. «Entendido» hides the entries so far for that administrator only
(`support_notice_dismissals`); a later entry shows the notice again. Editors and the support accounts never see it.

## Support Accounts

The first entry of an operator creates a user with `is_support = true`, role `admin`, the operator's email, the name
«Soporte RügerTek – <name>» and a random password nobody knows. Later entries reuse it and refresh the name. Because it
is a real user, every existing history (content revisions, operation actors, connector «connected by», editorial
reviews) already says which operator did what.

- `role: desarrollo` also gets `can_manage_custom_code` ([custom-code.md](custom-code.md)); `operacion` does not.
- It cannot sign in through the login form (`LoginRequest` excludes support accounts), never gets a password reset
  link, and its MCP keys are refused (`AuthenticateMcpApiKey`).
- During support it cannot create credentials that would outlive the session: changing its own password or email,
  two-factor setup and MCP key generation answer 403, and the attempt is recorded. It cannot change the support
  switch either.
- It is hidden from the client's user management (not listed, not counted, edit and delete answer 404), from
  reviewer and submission assignee lists, and from site emails to administrators (`User::clientAccounts()`).
- If the operator's email already belongs to an ordinary user of the site, that user is **never** reused or
  converted. The entry answers 409 with a clear message, and no session starts.

Support accounts are never deleted automatically, so their names keep the history readable after retention.

## Support Sessions

The Laravel session keeps only `support_session_id`. `EnforceSupportSession` runs on every admin request of a support
account. It needs support access to be allowed on the site and that id to point at an open `support_sessions` row of
the same user, before `expires_at`. Otherwise it signs the operator out. An expired session is closed with
`end_reason = expired` and the page says the support session ended. A support account without a support session gets
a 404. Activity does not extend the session.

`end_reason`: `exit` («Salir del modo soporte», `POST /admin/support/exit`), `logout`, `expired`, `replaced` (a new pass
opened in the same browser), `disabled` (the client turned support access off).

## What Is Recorded

`support_sessions`: `user_id`, `operator_email`, `operator_name`, `role`, `issuer`, `jti`, `reason`, `ticket`, `ip`,
`user_agent`, `started_at`, `expires_at`, `ended_at`, `end_reason`. Sessions opened before v2 have no reason; the
screens say so.

`support_audit`, one row per request during a support session:

- every admin request that is not GET/HEAD/OPTIONS;
- every page view (a full GET; the editor's background JSON/AJAX calls are not counted as views).

Each row stores `at`, `method`, `route_name`, `path` (**without the query string**), the subject the route points at
(`subject_type`, `subject_id`, and `subject_label`: the record's title or name read before the action, so a deleted
record keeps its name), `changed_fields` and the response `status` (403 for a blocked attempt, 500 if it crashed).

`changed_fields` holds the **names** of the submitted fields in dot notation (`settings.site_name`,
`variants.*.price`; lists collapse to `*`, depth 3, at most 60 names), uploaded file fields included. For settings
these are the setting keys. **No submitted value is ever stored**, sensitive or not: passwords, tokens, secrets, keys
and card data are recorded only by name, like every other field. The pass itself is never stored or logged; only its
`jti` is kept.

`support_pass_jtis` keeps used `jti`s until the pass could no longer be valid, so a pass works once even if the first
attempt failed later.

## Retention

`support:prune` runs daily (`routes/console.php`). It deletes sessions that started more than
`RUWEB_SUPPORT_RETENTION_DAYS` ago (365 by default) with their activity, and forgets expired `jti`s. The switch history
is kept.

## Activity Screen

Admin > Actividad de soporte (`/admin/support-activity`, administrators only, the client's included; editors get 403)
starts with the switch, then lists every session: operator, email, role, **reason and ticket**, when, state, duration,
number of changes and page views, and the first changes as readable lines. The detail page shows the session data
(reason, ticket, IP, browser, issuer, how it ended) and every entry with its submitted field names and status. The
same data, plus `meta.access`, is available read-only to agents through `support_activity_list`.

`SupportActivitySummary` builds the readable lines from the route and subject only:

- changes: «Cambió los ajustes del sitio», «Editó el producto «Remera»», «Borró el usuario «Pedro»», «Editó el
  conector Chatbot Amiba»;
- page views: known screens by name («Vio los ajustes del sitio», «Vio la lista de productos»), records by their
  subject («Vio el producto «Remera»», «Vio el conector Chatbot Amiba», with the connector's panel name instead of
  its key), forms to create («Abrió el formulario para crear una página»), and the path only for anything else
  («Vio /admin/…»).

## Try It Locally

```bash
php artisan support:keypair            # prints RUWEB_SUPPORT_PUBLIC_KEYS='{…JWK…}' and RUWEB_SUPPORT_DEV_PRIVATE_KEY=…
# paste both lines into .env (APP_ENV=local, APP_URL=http://localhost:8000), then:
php artisan config:clear
php artisan migrate
php artisan support:issue-pass ana@rugertek.com --name=Ana --reason="Revisar el catálogo de Meta" --ticket=TK-1 --ttl=900
```

`support:issue-pass` prints a single-use URL valid for 30 seconds; open it in the browser. `--reason` is required and
follows the claim rules. Both commands refuse to run unless `APP_ENV` is `local` or `testing`, and
`support:issue-pass` also needs `RUWEB_SUPPORT_DEV_PRIVATE_KEY`. Real passes are issued only by the console. On
Windows PHP builds without a default `openssl.cnf`, key generation retries with an empty configuration file.

## What RuWeb Cloud Still Needs

The console side is a separate change in `RugerTek/ruweb-cloud`, agreed with its maintainer:

- Cybersecurity creates the ES256 key (P-256) in Azure Key Vault, not exportable, and grants the console's managed
  identity only the `sign` permission on it. The public key goes to every site as `RUWEB_SUPPORT_PUBLIC_KEYS`
  ([Keys](#keys)).
- An «Entrar al admin» button per site, only for authenticated RügerTek operators whose email domain matches
  `RUWEB_SUPPORT_OPERATOR_DOMAIN`, with a **required reason field** (10–500 characters, plain text) and an optional
  ticket. It builds the payload of [Pass Format](#pass-format) with `aud` = the site's `APP_URL` origin, a random
  `jti`, `exp = iat + 30` (never more), the operator's `role` and an optional `session_ttl`.
- Signing: `message = "ruweb-support-pass.v2\n" + payloadSegment`; `digest = SHA-256(message)`; call Key Vault
  `POST {vault}/keys/ruweb-support-pass/{version}/sign?api-version=7.4` with `{"alg":"ES256","value":"<base64url
  digest>"}`; the response `value` (base64url r‖s, 64 bytes) is the signature segment as is. The pass is
  `payloadSegment + "." + value`. It opens the URL in a new tab, without logging the pass.
- Optionally, its own log of issued passes (operator, site, reason, `jti`, time), to cross-check with each site's
  record.
- The rotation procedure above, and an updated list of sites with their `APP_URL` origin.

The hidden agent package is not involved. Support access needs nothing from it.

## Review Before Merging

Support access opens every client's admin, so the pull requests that change it, here (`app/Services/SupportAccess`,
the support controllers, middleware, routes, views and this document) and in RuWeb Cloud (the «Entrar al admin»
button and the Key Vault signing), are reviewed by RugerTek Cybersecurity before they are merged. The review checks
at least: the key is non-exportable and only the console identity can `sign` with it; the pass lifetime and clock
tolerance; the reason is required on both sides; the switch cannot be changed by a support account or through MCP;
nothing logs the pass.
