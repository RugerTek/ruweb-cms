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
authentication file; it does not edit global configuration. Its read-only sandbox disables shell
and execution tools and exposes only six fixture MCP tools, explicitly approved in that private
configuration. The client performs acceptance operations only; it does not implement product code.

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

## Phase seven: portable page composition (E2E-07)

Build the candidate once, then prepare disposable full and essential copies with their own vendor,
bootstrap caches, storage and never-used MySQL schemas using the phase-zero guard. Exclude `.git`, `.env`,
`.env.*`, `node_modules`, `storage`, `public/storage`, **`public/hot`** and generated bootstrap caches.
Privately copy the normal `.env` into each disposable copy only to obtain the local database account.
Select the profile in that copy's versioned `config/cms.php`. Run profiles sequentially on loopback 8018.
Use the copied production assets throughout; no development server or asset rebuild is allowed between
initial publication and subsequent composition edits.

After `phase-zero-fixture.php`, run `php tests/E2E/phase-five-control.php identity` for review identities.
Start the copy with `phase-three-router.php`, then execute:

```bash
PHASE7_PROFILE=essential python3 tests/E2E/phase-seven-http.py
PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-seven-browser.cjs
PHASE7_PROFILE=essential python3 tests/E2E/phase-seven-http.py --mutate
PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-seven-browser.cjs
PHASE7_PROFILE=essential python3 tests/E2E/phase-seven-http.py --restore
PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-seven-browser.cjs
```

Use `PHASE7_PROFILE=full` in the full copy. Keep `PHASE0_DATABASE` and `PHASE0_STORAGE` exported in every
stage, as with earlier fixtures. Browser stages read the profile and stage from the private fixture.
The harness discovers the same 52 MCP tools and the canonical `html`, `empty` and `composed` schemas;
it creates, translates, publishes, proposes, reviews, restores and republishes using the existing tools.
Translation receives distinct English HTML, component/interactive copy, title and SEO through MCP. The Spanish origin must remain unchanged; browser lang/canonical/English copy and media IDs are checked. Stable block IDs, ordered list semantics, registered structured media and interactive parameters are checked.
The loaded compiled accordion is exercised by real summary clicks and must close its previously open sibling.
The browser checks public and pinned historical preview at 1440 and 390 pixels, image bytes and response
content type, decoded responsive images, SEO canonical, shared header/footer (intentionally absent on
`empty`), no overflow and standalone `cms-grid-2` desktop/mobile columns. Script, event, inline CSS, SVG,
iframe, encoded JavaScript and data URL attacks must remain inert in the actual DOM, while admitted content
and permitted root-relative links remain visible. Public build SHA-256 must remain unchanged across edits.

To verify essential-to-full compatibility, stop its server, change only the disposable profile to `full`,
regenerate caches with `phase-two-control.php cache`, clear fixture leases with its `unlock` action and
start a new server. Run `PHASE7_STAGE=full-ui PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node
 tests/E2E/phase-seven-browser.cjs`. The actual admin editor URL must redirect to editorial history with
the translated compatibility notice and no visual editor form. The sidebar is closed for the screenshot.
No persistent workers run in this fixture; an actual deployment must restart them.

Retain only `phase-seven-results.json`, `phase-seven-browser-*-results.json` and screenshots. The
`phase-seven-fixture.json` contains a confidential preview URL: delete it with the credential manifest,
copied `.env`, generated config/routes caches and all private server/mail logs after guarded schema cleanup.
Stop every server first. Run accumulated E2E-00/01 on full and E2E-03/05 in both profiles using their
existing stages; preserve their sanitized results independently. Never point this fixture at a real database.

## Phase eight: trusted browser snippets (E2E-08)

Build the candidate **after the new Blade screens** with `npm run build`, then prepare independent full and
essential disposable copies and fresh guarded MySQL fixtures as in phase seven. Copy the production build;
exclude `.env`, `public/hot`, storage, cached config/routes and public storage links. Never use the real
application/database. Set `PHASE8_PROFILE` to the copy's profile, `PHASE0_DATABASE`/`PHASE0_STORAGE` to its
isolated fixture, and provision with `phase-zero-fixture.php` then `phase-eight-control.php identity`.
The latter creates an explicit Custom Code credential and a second denied administrator; no existing full
credential inherits executable permission. Use at least four `PHP_CLI_SERVER_WORKERS` to exercise concurrent
HTTP requests, and run profiles sequentially on free loopback 8018.

Start the guarded phase-zero router with `OAUTH_CLIENT_ID=e2e-phase8` and
`OAUTH_ALLOWED_REDIRECT_URIS=http://127.0.0.1:8018/oauth-return`. The harness has these stages:

1. `python3 tests/E2E/phase-eight-http.py initial` creates three templates and their English translations via
   cookie-free editorial MCP, then pins an ordinary preview.
2. `PHASE8_STAGE=create PLAYWRIGHT_PACKAGE=/absolute/path/to/playwright node tests/E2E/phase-eight-browser.cjs`
   authors CSS through the real panel and activates the selected exact version.
3. Run `phase-eight-http.py` with each stage `global`, `exact`, `pending`, `deactivated`, `reactivated`; after
   **each** run execute the browser script without PHASE8_STAGE so it reads the private fixture's current stage.
4. `php tests/E2E/phase-eight-control.php disable`, then browser with `PHASE8_STAGE=killed`; `enable`, then
   browser with `PHASE8_STAGE=recovered`. Run HTTP `harmful`, browser current stage, CLI `disable`, then browser
   `PHASE8_STAGE=harmful-killed`. The deliberate layout break is restricted to one disposable public page.
5. Run `phase-eight-security.cjs` with the same Playwright environment: real HTTP rejects seven conflicting
   OAuth query/body parameters, reads the panel CSS byte-for-byte via exact MCP version, and compares panel/MCP
   evaluation output. Optional read-only `PHASE8_STAGE=capture-panel` refreshes desktop/mobile panel captures.

All browser stages use Chromium, verify six destination/language documents, deterministic positions/order,
one marker execution, exact translation scope, inert pending versions, preview/login/OAuth/admin exclusion,
no horizontal overflow and 1440/390 captures. Global also executes an explicitly acknowledged test in an
opaque-origin iframe and verifies parent-session document isolation. The eight synchronized same-key save
requests and eight activation requests run against real independent HTTP workers/MySQL; results and audit
counts prove one committed operation per key. Existing editorial scopes and a custom-code scoped administrator
without the account grant are denied without mutation. Normal admin Google Fonts requests are blocked by
Chromium and recorded as blocked assets; no external request is allowed, no tracking provider runs.

Also verify `cms:custom-code disable|status|enable` in the isolated copy with an unreachable database port;
this recovery path must not need the database or public frontend. Preserve only result JSON, representative
screenshots and SHA-256 maps of the exact application source plus production build. The private fixture and
manifest contain credentials/preview URLs; never retain them, raw HTTP traces or server/mail logs as evidence.
Stop servers, execute guarded cleanup for each schema, remove the private `.env`, caches, manifests/logs and
kill-switch marker, then delete disposable copies. Snapshot portability is an explicit phase 10 contract,
not part of this acceptance claim.


## Phase eleven: accumulated candidate regressions on actual PHP 8.3

`phase-eleven-accumulated.py` copies the current candidate into a private temporary directory for each
phase/profile, copies an independently installed vendor tree, selects the profile only in that copy,
and creates a never-used schema through the existing guard. Set `PHP83_BIN` to the directory containing
the isolated actual PHP 8.3 runtime and `PHP83_VENDOR` to its independently installed vendor directory.
`PHASE11_PHASES` selects comma-separated phases (0 means E2E00 plus E2E01); `PHASE11_PROFILES` selects
profiles. All original acceptance requests remain on loopback 8018 and run sequentially. Stop any
other fixture on that port first. Production assets are copied from the candidate; `public/hot` is excluded.

E2E02 copies the versioned deterministic historical delivery from `tests/Fixtures/admin-profiles` into
the disposable default template, editor partials and translations. It reruns real HTTP, browser and
cached-profile transitions, but does not claim new agent authoring. The transition process uses one
HTTP worker because its original start/terminate recipe owns one server process. E2E04 runs real
`cms:install`, real panel identity/credential creation, the restricted Codex MCP client and both profile
browser stages. E2E09 full creates its expected draft through a real Codex MCP case before its native
preview assertion; both profiles rerun 2FA, OAuth, password recovery and asynchronous HTTP/worker stages.
E2E00/01 runs in both profiles: Full includes specialized visual editing; Essential checks the operational panel and authorized preview. E2E04 starts in essential and explicitly transitions to full.

The PHP runtime needs its ordinary production ini semantics, including `short_open_tag=Off` for XML
rendering, a sufficient memory limit, and all dependencies/extensions reported by
`composer check-platform-reqs`. Composer installation, the full suite, JavaScript suite, build and
conventions are separate isolated gates; a configured Composer platform version does not prove runtime.

The runner retains only result JSON and screenshots under
`docs/plans/phase-11-evidence/runtime-accumulated`, stops its server group, drops its guarded schema and
destroys its private copy. `.env`, credential/preview manifests, auth/config, server/mail/client logs and
recovery codes stay private and are deleted. Every completed fixture verifies the original `.env` hash.
E2E10 update/snapshot/rollback and the new E2E11 pilot remain separate cumulative gates.
