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

Separately reconnect a real Codex client, open a fresh conversation and verify 22 discovered tools,
including `menus_list` and `settings_list`. An HTTP tools/list pass does not prove client discovery.

After stopping the server, revoke test credentials and remove **only this run's generated schema** with:

```bash
php tests/E2E/phase-zero-cleanup.php
```

The cleanup checks the generated database-name pattern, requires its fixture manifest, and refuses any
other database. It removes the credential manifest but retains non-secret local test evidence. Storage and
logs can be removed later once evidence has been reviewed. No production migration is needed by these fixes.
