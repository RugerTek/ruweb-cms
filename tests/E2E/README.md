# Phase zero: isolated HTTP acceptance

Requires PHP, Composer dependencies, Python 3, compiled Vite assets and a local MySQL account permitted to
create databases. Database credentials come from the normal environment; no real site records are changed.
The fixture refuses to reuse a schema. All HTTP targets are fixed to loopback port 8018. Do not run it if
that port is occupied by another service. Test files are never registered in production routes.

From the repository root, prepare a new temporary environment:

```bash
export PHASE0_STORAGE=$(mktemp -d /tmp/ruweb-phase0.XXXXXX)
export PHASE0_DATABASE=ruweb_phase0_$(php -r 'echo bin2hex(random_bytes(6));')
mkdir -p "$PHASE0_STORAGE"/framework/{cache/data,sessions,views} "$PHASE0_STORAGE"/logs "$PHASE0_STORAGE"/app/{public,private}
php tests/E2E/phase-zero-fixture.php
php -S 127.0.0.1:8018 -t public tests/E2E/phase-zero-router.php
```

Keep that server running. In a second terminal with the same `PHASE0_STORAGE` and `PHASE0_DATABASE` values:

```bash
python3 tests/E2E/phase-zero-http.py
php tests/E2E/phase-zero-worker.php
```

Run each fixture once. A repeated run requires a fresh schema/storage directory. The HTTP test uses real
API keys with four role/scope combinations, real middleware and real persistence. It creates and reads
content through MCP and admin HTTP, checks public output, submits the real contact form and verifies its
inbox. The worker uses the database queue and writes mail to the isolated log; it never sends external mail.
Only Google's external verification response is stubbed by the test router. CSRF, form tokens, elapsed
fill time, honeypot and throttle remain active. The 105 synthetic media rows test pagination only; the
seeded placeholder files are used for actual image rendering.

Evidence: `http-results.json` contains checks and the draft ID; `logs/laravel.log` is the test mailbox.
`manifest.json` contains ephemeral credentials and must not be committed or attached to reports.
The fixed UI account is `phase0@example.test` / `Phase0-Test-Only-2026!`, only in the fresh loopback fixture.
For browser verification visit `/panel-baseline`, then `/login` and `/admin/submissions/contact` (sent).
The row edited by MCP can be opened in the admin using the draft ID from the result file.

Separately reconnect a real Codex client, open a fresh conversation and verify the current 46 discovered tools,
including `menus_list` and `settings_list`. An HTTP tools/list pass does not prove client discovery.

After stopping the server, revoke test credentials and remove **only this run's generated schema** with:

```bash
php tests/E2E/phase-zero-cleanup.php
```

The cleanup checks the generated database-name pattern, requires its fixture manifest, and refuses any
other database. It removes the credential manifest but retains non-secret local test evidence. Storage and
logs can be removed later once evidence has been reviewed. No production migration is needed by these fixes.

## Phase one: shared page operations (E2E-01)

Reuse the same fresh fixture and loopback server above. After `phase-zero-http.py` and the worker pass,
run `python3 tests/E2E/phase-one-http.py` with the same two isolation variables. Run it once per fixture.
It creates a draft through the admin session, reads/edits/publishes through cookie-free MCP, parses the
reopened admin form to compare exact persisted field values, and checks public content and SEO without
session. A second independent draft exercises equivalent REST edits/publication and authorization
rejections. RPC denials assert their exact HTTP status, JSON-RPC code and authorization message;
a generic internal failure does not count as an authorization pass. Read-only edits and editor publication must fail without changing either record.

`phase-one-results.json` records the five acceptance checks and both non-secret page IDs. Use the same
cleanup procedure to revoke keys and drop only this run's isolated schema. E2E-00 remains mandatory;
E2E-01 supplements it and never replaces the phase-zero script.

## Phase two: profile-aware development (E2E-02)

Build the candidate once with `npm run build`. Create **two disposable copies**, with independent vendor,
bootstrap caches, storage and fresh MySQL schemas. Copy the candidate's AGENTS, skills and docs as well as
source, and exclude `.git`, `storage`, `node_modules`, `public/storage` and **`public/hot`**. Never let a
copy reuse the checkout's Vite development server: only the copied production build is valid evidence.
Keep any local `.env` used to obtain the test database account private and delete it after cleanup.
Select `admin_profile` in each copy's versioned config, one full and one essential. Do not modify the live
project configuration or database. Confirm loopback 8018 is free before starting each server; run copies
sequentially because the shared harness intentionally fixes that port.

Give two real Sol 6.1 agents the identical [brief](../../docs/plans/phase-2-evidence/brief.md), scoped to their
own copy. Ask them to discover capabilities and follow the copy's AGENTS/skills. Preserve their context,
diffs and specific-test results; scripts that generate pretend agent submissions are not a substitute.
The deterministic equivalent lives in `tests/Fixtures/admin-profiles` and `ProfileDevelopmentFixtureTest`;
the generated testimonials belong to the exercise, not starter product content.

For each completed delivery, use the phase-zero fresh-fixture setup above. Set `PHASE2_PROFILE` to its
selected profile. With that copy's loopback server active, execute:

```bash
python3 tests/E2E/phase-two-http.py
php tests/E2E/phase-two-control.php unlock
PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-two-browser.cjs
```

Install a compatible Playwright Chromium runtime beforehand. The browser script refuses `public/hot` and
blocks every origin except the isolated loopback server. In full it verifies loaded build entrypoint,
synchronous editor initialization, recovery-row reconstruction, add/reorder/confirmed-delete, form save,
public output and desktop/mobile captures. In essential it checks the entrypoint requests, absence of
specialized editor/TinyMCE downloads on operational listings, media upload modal and denied editor URL.
This is not a claim that every remaining operational screen is finalized; that panel is phase 4.
`unlock` only clears edit leases in the fresh schema accepted by the guarded bootstrap, so a prior HTTP
session cannot prevent the browser from opening the same exercise record.

Stop the server, then execute `python3 tests/E2E/phase-two-transitions.py`. It changes only the disposable
config, regenerates config/routes caches, starts and stops fresh server processes, compares CLI/REST
discovery and exact persisted/public content, probes missing editors and literal-image convention failures,
and verifies invalid-profile failure through the real local command. It restores the initial copy config.
No long-lived queue workers run in this fixture; restart such workers on an actual profile deployment.

Retain `phase-two-*-results.json`, context/diffs and screenshots as non-secret evidence. Also run accumulated
E2E-00/01 on full and inspect their results. After all server processes stop, use `phase-zero-cleanup.php`
to revoke keys and drop only each newly generated schema, then delete the ephemeral manifest, copied `.env`
and generated config cache (it embeds credentials). Do not commit those files or server/mail logs.


## Phase three: remote editorial operation (E2E-03)

Prepare two disposable copies and fresh MySQL fixtures as above, selecting `full` and `essential` in their
versioned configuration. Use the same fixture administrator and full bearer for both REST and RPC within
each deployment. Set `PHASE3_PROFILE` to the selected profile. Start the loopback server with
`tests/E2E/phase-three-router.php`, which adds a fixture-only controllable clock; production routes and
bootstrap never read its clock file. Run the profiles sequentially on port 8018:

```bash
python3 tests/E2E/phase-three-http.py
PHASE3_STAGE=draft PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-three-browser.cjs
python3 tests/E2E/phase-three-http.py --publish
PHASE3_STAGE=public PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-three-browser.cjs
```

The first stage discovers the actual home template schema, configures English through HTTP, transfers a real
1600×900 PNG with a credential-bound upload ticket, retries its receipt, edits localized image metadata,
creates and translates a draft, edits its slug/SEO/copy with revision checks, creates localized menu links,
configures a portable CTA and localized footer through batch settings, rejects malformed menu revisions,
and checks media usage protection. Draft/public/sitemap exclusion and anonymous preview rendering are
verified before publication. The second stage publishes both locales, checks canonical/hreflang and image
bytes, then schedules another page and advances the isolated clock across its publication time. The browser
stages decode the actual responsive images and capture both languages at desktop and mobile widths.
No browser login or cookie identity is used for MCP. Only fixture setup, image generation and clock injection
use the local test controls; functional editorial operations go through the HTTP transports.

Retain `phase-three-results.json`, `phase-three-browser-*-results.json` and screenshots as non-secret
acceptance evidence. `phase-three-page.json` includes confidential preview URLs: keep it private with the
credential manifest, omit it from published evidence, and delete it after browser verification. Stop servers,
run the guarded `phase-zero-cleanup.php` for each newly generated schema, and delete copied `.env` files and
configuration caches. Continue running cumulative E2E-00/01 and the documented E2E-02 regression separately.

## Phase four: person-to-agent handoff (E2E-04)

Use a disposable copy with its own vendor, storage, bootstrap caches, a never-used
`ruweb_phase0_` MySQL schema and the production Vite build. Exclude `.git`, `node_modules`,
`storage`, `public/storage`, `public/hot` and bootstrap caches. Keep any `.env` carrying the
local database account private. Select `essential` in the copy's versioned config. Set the
same `PHASE0_STORAGE` and `PHASE0_DATABASE` isolation variables used by earlier phases.

Unlike earlier fixtures, provision E2E-04 with `php tests/E2E/phase-four-install.php`. This
executes the **real `cms:install` command**, including migrations, placeholders, seeded public
pages and the first administrator. Its guard rejects an existing schema. Do not run the
phase-zero fixture on this database. Start the copy with the phase-zero router on free
loopback port 8018, then execute the following stages:

```bash
PHASE4_STAGE=identity PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-four-browser.cjs
PHASE4_CODEX_CLIENT=/absolute/path/to/codex python3 tests/E2E/phase-four-client.py
PHASE4_STAGE=essential PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-four-browser.cjs
PHASE4_STAGE=essential-bar PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-four-browser.cjs
```

The browser creates an editor account through the first administrator's session, logs in as
that editor and issues its own draft-only credential. The real Sol 6.1 Codex CLI then discovers
MCP, creates/reads/updates a draft using revision checks and receives an explicit publication
denial. The client uses an ephemeral home with only this fixture server and a copied existing
authentication file; it does not edit global configuration. The explicit CLI sandbox/approval
bypass permits MCP tool calls in this isolated acceptance run, whose prompt permits only the
fixture MCP tools. It is not a deployment setting.

The essential browser stage checks preserved operational routes, readonly listing/status,
authenticated native preview, anonymous draft 404, a real media search, canonical site-name
setting edit and public effect, direct editor 404, and no full-editor/TinyMCE downloads. Desktop
and mobile captures assert no horizontal document overflow. The separate essential-bar stage
checks the authenticated public bar has no links to unavailable editors. Network evidence is
limited to path/method/status and script paths; raw browser traces with session tokens are not
retained. Credential screens are never captured.

Stop the server, change only the copy's profile to `full`, regenerate config/routes caches with
`php tests/E2E/phase-two-control.php cache`, clear any fixture edit leases with its `unlock` action,
and start a fresh server. Run `PHASE4_STAGE=full` with the browser script: it checks the exact
agent content survived and makes/saves/reopens a real visual heading edit. No persistent workers
run in this fixture. Profile deployment on an actual site must restart them as documented.

Run accumulated E2E-00/01 in a separate full fixture and E2E-02/03 in both profiles, sequentially
on 8018. Keep non-secret result JSON, path-only network traces, code/build fingerprints and
captures. Stop every server, execute guarded phase-zero cleanup for each schema, and remove
all ephemeral client homes/auth, private Codex transport logs, manifest, `.env`, config caches
and server/mail logs. Never preserve a credential or preview URL as acceptance evidence.


## Phase five: exact review and recovery (E2E-05)

Prepare disposable full and essential copies with separate vendor/bootstrap caches, storage and unused MySQL
schemas as above. Set `PHASE5_PROFILE` to the copy's configured profile. Reuse the phase-zero fixture and run each server sequentially on loopback 8018 with
`phase-three-router.php` for isolated clock control. The candidate includes the receipt migration; production
Vite assets remain unchanged by this phase. Run:

```bash
php tests/E2E/phase-five-control.php identity
python3 tests/E2E/phase-five-http.py
PHASE5_STAGE=proposal PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-five-browser.cjs
python3 tests/E2E/phase-five-http.py --complete
PHASE5_STAGE=restored PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-five-browser.cjs
```

The fixture identities are setup only. Real bearer HTTP performs proposals, exact review requests, comments,
assigned administrator approval, conflict rejection, historical restoration/review/republishing, preview
revocation and retirement. Public output and sitemap checks use anonymous requests; the browser confirms
public/preview rendering and denied revoked/expired capabilities without an admin session or Live Editor.
Retain only sanitized result JSON and screenshots; the private preview/credential manifest is deleted by the
guarded cleanup. Repeat affected E2E-00–04 separately using their documented stages and stop all servers before
cleanup. Never change the repository's profile, `.env`, production database or caches to run this fixture.

## Phase six: visitor follow-up and delivery (E2E-06)

Prepare fresh disposable full and essential copies, each with independent vendor/bootstrap caches, storage,
production Vite assets and a never-used MySQL schema, using the guarded phase-zero fixture above. Set
`PHP_CLI_SERVER_WORKERS=4` when starting the fixture server so concurrent HTTP mutations execute in independent
processes against MySQL. Set `PHASE6_PROFILE` to the copy's configured profile and run profiles sequentially on loopback 8018 with the
phase-zero router. Functional submission operations use the real browser or cookie-free MCP HTTP;
fixture controls only create identities, select a worker mail transport, advance its clock and assert evidence.

```bash
php tests/E2E/phase-six-control.php identity
PHASE6_STAGE=submit PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-six-browser.cjs
python3 tests/E2E/phase-six-http.py
PHASE6_STAGE=tracked PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-six-browser.cjs
php tests/E2E/phase-six-control.php fail-worker
PHASE6_STAGE=failed PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-six-browser.cjs
python3 tests/E2E/phase-six-http.py --recover
php tests/E2E/phase-six-control.php deliver-worker
python3 tests/E2E/phase-six-http.py --complete
PHASE6_STAGE=sent PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-six-browser.cjs
php tests/E2E/phase-six-control.php deliver-worker
```

The visitor fills and submits the actual public form through Chromium with its real CSRF and signed form token,
a real minimum fill wait and an opaque reCAPTCHA input. Only Google's exact external verification endpoint is
stubbed by the guarded router. The browser blocks all external origins. The same persisted row is read through
MCP, assigned, annotated and advanced in workflow, then reopened in the admin at desktop/mobile widths.
Invalid CSRF/form tokens, an unpublished originating page, invalid/expired credentials and disallowed
roles/scopes are rejected without creating or changing a submission. Eight simultaneous same-key note requests
and eight simultaneous recovery requests prove one receipt and one queue reservation across real workers.

The real database queue worker exhausts the job's four configured attempts against a controlled failing mail
transport. An isolated worker clock advances across the actual backoff delays; no job or submission row is
edited by the fixture. Failed delivery remains observable while the visitor data survives. MCP queues recovery,
replays its receipt and rejects duplicate reservation. The recovered worker delivers exactly once to the local
mail log; subsequent retries and another worker run cannot send again. No external email is sent.

Retain sanitized `phase-six-results.json`, browser result JSON and screenshots. Treat the fixture manifest and
mail/server logs as private. Stop every server before guarded `phase-zero-cleanup.php`, remove copied `.env`
and configuration caches, delete private logs/fixture files, and repeat accumulated E2E-00/01 in a separate fresh
full fixture. Do not modify the repository's profile, production database or caches to run acceptance.
