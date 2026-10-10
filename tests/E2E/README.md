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

Separately reconnect a real Codex client, open a fresh conversation and verify the current 36 discovered tools,
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
