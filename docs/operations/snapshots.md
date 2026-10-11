# Snapshots (`.appbackup`)

> The repository carries code; everything dynamic — database rows and public uploads — moves between
> installations as an `.appbackup` archive. Source of truth for the format, the pre-restore inspection and the
> housekeeping. The deploy guides reference this file instead of repeating it.

## Contents

- What An Archive Contains
- Archive Layout
- Inspect Before Restore · Risk Keys · Acceptance Rule
- Application Version
- Content Revisions
- URLs And Domains
- Operating It (CLI and admin)
- What Restore Does
- Housekeeping
- Extension Points

A snapshot contains:

- one JSONL file per database table, one row per line, ordered by `id` or `created_at`
- every file under `storage/app/public` (the `public` disk)
- `manifest.json` describing the origin and the table list
- `checksums.json` with a SHA-256 per file

A snapshot never contains source code, `vendor`, `.env`, application keys, cache, sessions, Live Editor leases, queue tables or password reset tokens.

The `migrations` table is exported for inspection but is **never written into the destination**. The destination keeps its own migration history so `php artisan migrate` stays truthful after a restore.

## Archive Layout

```
manifest.json
checksums.json
database/<table>.jsonl
uploads/<relative path under storage/app/public>
```

`manifest.json` (format version 2):

```json
{
  "format": "ruweb-cms.appbackup",
  "version": 2,
  "created_at": "2026-09-13T17:18:39+00:00",
  "app": { "version": "0.35.0", "laravel_version": "...", "env": "production", "url": "https://example.com", "commit": "abc123" },
  "database": {
    "connection": "mysql", "driver": "mysql",
    "tables": [{ "name": "pages", "file": "database/pages.jsonl", "rows": 12, "checksum": "..." }],
    "excluded_tables": ["cache", "sessions"],
    "migrations": ["0001_01_01_000000_create_users_table", "..."],
    "schema": { "pages": ["id", "locale", "title", "..."] }
  },
  "uploads": { "logical_root": "public", "files": 40, "bytes": 1048576 },
  "history": { "head": "<sha256 of the newest content revision>", "count": 1234 }
}
```

Format version 1 archives are still accepted and raise the `legacy_format` warning.

## Inspect Before Restore

Every restore is preceded by an inspection that never touches the destination. The inspection produces a report with:

- a summary of the manifest (origin URL, creation time, driver, table and upload counts)
- a list of **risks**, each with a stable `key`, a `severity`, an English `message` and machine-readable `details`

Severity contract:

| Severity | Meaning | Can it be accepted? |
|----------|---------|---------------------|
| `blocking` | Restoring would break the destination | Never |
| `warning` | Restoring is possible but may lose data or behave differently | Yes, explicitly by key |
| `info` | Descriptive only | Not applicable |

Structural problems — invalid ZIP, missing or mismatched checksums, unsafe paths, schema-qualified table names — are not risks. They abort inspection with a `SnapshotException`.

### Current risk keys

| Key | Severity | When |
|-----|----------|------|
| `missing_tables` | blocking | The snapshot lists tables the destination does not have. Update code and run migrations first. |
| `newer_snapshot` | blocking | `app.version` in the snapshot is greater than `config('cms.version')` here. Update the code first. |
| `unknown_migrations` | blocking | The snapshot ran migrations this installation has not. Its code is newer or from another branch. |
| `missing_columns` | blocking | Snapshot rows carry columns a destination table lacks. |
| `required_columns_missing` | blocking | The destination has NOT NULL columns without default that the snapshot rows do not provide. |
| `older_snapshot` | warning | This installation ran migrations after the snapshot was taken. `details.migrations` lists them. Data migrations among them will not re-run on the restored rows. |
| `unknown_origin_version` | warning | The manifest has no `app.version` (format 1 archives). |
| `diverged_history` | warning | This installation recorded content changes the snapshot does not contain. `details.changes` lists up to 50 of them, `details.diverged` the total. |
| `unknown_history` | warning | This installation has revisions but the snapshot carries no `content_revisions` table, so containment cannot be verified. |
| `history_contained` | info | Every revision recorded here is present in the snapshot: a clean fast-forward. |
| `legacy_format` | warning | The archive uses an older format version, so newer checks cannot run. |
| `driver_mismatch` | warning | The snapshot was taken on a different database driver. |
| `origin_url_differs` | info | The snapshot's `app.url` differs from this installation's. Stored URLs are rewritten during restore (see URLs And Domains). |

New checks must be added as risks with a new key, a translation `admin.snapshot_risk_<key>` in `lang/*/admin.php`, and a row in this table.

### Acceptance rule

`SnapshotService::restore($archive, array $accepted)` refuses to run while any blocking risk exists, or while any warning key is missing from `$accepted`. There is no global bypass: each warning is accepted by its own key. The CLI `--yes` flag (and its older alias `--force`) accepts every warning the report contains, not future ones.

## Application Version

The application version is the single line in the `VERSION` file at the repository root, exposed as `config('cms.version')`. It is bumped together with `CHANGELOG.md` by the `changelog-version` skill, and `tests/Unit/VersionFileTest.php` fails when the two disagree.

Version rules at restore time:

- **Snapshot newer than destination**: blocked. Deploy the newer code and run `php artisan migrate`, then restore.
- **Same version and same migration list**: normal restore.
- **Snapshot older than destination**: allowed after accepting `older_snapshot`. The rows are inserted into the newer schema; columns the snapshot lacks take their defaults. Data migrations that ran after the snapshot are **not** replayed, so prefer restoring into a checkout of the snapshot's version and then updating code and migrating when those migrations matter.

## Content Revisions

Every create, update or delete on a tracked model appends one row to `content_revisions`: a unique `hash`, the previous head as `parent_hash`, the entity type and id, the action, the user and a timestamp. No payload, no diff. Tracked models carry the `RecordsContentRevisions` trait: `Page`, `Post`, `Media`, `Setting`, `SettingTranslation`, `Menu`, `MenuItem`, `Taxonomy`, `Locale`. Detail models coalesce into their parent (`MenuItem` → `Menu`, `SettingTranslation` → `Setting`), and repeated writes to the same subject within one request record once.

Pivot rows (`mediables`, `taxables`) are not tracked on their own; they always change alongside a tracked parent save.

### How containment is checked

The whole table travels inside every snapshot. On inspection the destination streams the snapshot's hashes into a set and walks its own revisions:

- every destination hash present in the set → `history_contained` (info). The snapshot has everything this installation has.
- some destination hash missing → `diverged_history` (warning) with the list of those changes. Restoring discards them; the automatic pre-restore backup keeps a copy.
- destination has revisions but the snapshot has no ledger → `unknown_history` (warning).
- destination has no revisions (fresh install) → nothing to check.

It is a set membership test, not a chain walk, so concurrent writes and pruning never produce false alarms. Because restoring replaces the table with the snapshot's, the next comparison is exact again.

**Never add a marker row after a restore.** A row that exists only on the destination would make the next upload from the source look diverged.

### Cost and retention

One insert per tracked write, about 200 bytes per row. Nothing runs on the public read path. `snapshot:prune` removes rows older than 90 days while always keeping the newest 10,000 (`--revision-days`, `--revision-min`). If a destination is so far behind that its retained rows predate the snapshot's retained window, the comparison reports them as diverged; accept the risk knowingly or take a fresh snapshot.

To bypass the ledger for bulk imports:

```php
app(\App\Services\Revisions\ContentRevisionLedger::class)->withoutRecording(fn () => $importer->run());
```

## URLs And Domains

Content moves between domains (`http://localhost:8000` → `https://example.com`), so two rules apply:

1. **Storage is domain-neutral.** Anything the system writes itself must not embed the current origin. `App\Support\SiteUrl::toRelative()` turns a URL pointing at this installation into a root-relative path, and it is applied by `RichTextSanitizer` to every `href`/`src`, and by the menu controllers to custom links. Responsive variants store a disk-relative `path`; `Media::srcset()` builds the URL at render time. The public layout carries `<base href>`, so root-relative paths resolve. New code must follow the same rule: store ids or paths, build absolute URLs at render time.
2. **Restore rewrites what slipped through.** Legacy rows and hand-pasted absolute links exist. When the manifest `app.url` differs from this installation's `app.url`, every string value of every restored row has the old origin replaced by the new one, in both the plain form (`http://host`) and the JSON-escaped form (`http:\/\/host`). Only the origin changes; paths are untouched. The `origin_url_differs` info risk announces it, and the restore result reports `url_rewritten`. Override with `--url-from` / `--url-to` on the CLI when a format 1 manifest lacks the origin.

For a live database that changed domain without a snapshot:

```bash
php artisan cms:rewrite-url --from=http://localhost:8000 --to=https://example.com --dry-run
php artisan cms:rewrite-url --from=http://localhost:8000 --to=https://example.com
```

The command skips runtime tables and `users`, and tables without an `id` column.

## Operating It

### CLI (preferred for production and large archives)

```bash
php artisan snapshot:create --name=origin
php artisan snapshot:inspect /path/to/origin.appbackup          # report only; exit 1 if blocking
php artisan snapshot:restore /path/to/origin.appbackup           # prints report, asks per warning when interactive
php artisan snapshot:restore /path/to/origin.appbackup --accept=driver_mismatch
php artisan snapshot:restore /path/to/origin.appbackup --yes     # accept all current warnings (scripts)
```

Non-interactive runs (cron, CI) fail with exit code 1 on any unaccepted warning and print the exact `--accept=<key>` to use.

### Admin panel

`Developer tools → Migration` implements the same contract in two steps:

1. **Upload** stores the archive under a random 40-character token, inspects it and redirects to a review screen. Nothing is restored.
2. **Review** shows the summary and the risks. Warnings render as checkboxes; blocking risks disable the restore button. Restoring requires confirming the shared modal.

Pending uploads are listed on the Migration tab with review and discard actions. Uploads not restored within 24 hours are pruned automatically.

HTTP uploads are capped at 500 MB by validation, and web servers often reject large bodies before Laravel runs. Use the CLI for anything large.

## What Restore Does

1. Extract with path safety checks, validate manifest and checksums, build the report, evaluate accepted risks.
2. Create an automatic `pre-restore-<timestamp>.appbackup` of the current state.
3. Enter maintenance mode.
4. Inside one transaction with foreign key checks disabled: delete every restorable table in reverse order, then insert rows in batches of 500.
5. Replace `storage/app/public`, keeping a rollback copy until the copy succeeds.
6. Run `storage:link` and `optimize:clear`, leave maintenance mode (also on failure).

If a process dies while maintenance mode is active, run `php artisan up` after inspecting the failure.

## Housekeeping

Everything the module writes to disk is transient except archives created by name. Categories and retention:

| Category | Location | Retention |
|----------|----------|-----------|
| Pending uploads | `storage/app/private/snapshots/uploads` | 24 hours |
| Pre-restore backups | `storage/app/private/snapshots/pre-restore-*.appbackup` | Newest 3 |
| Download temp files | `storage/app/private/snapshots/tmp` | 24 hours |
| Work folders | `snapshot-work`, `snapshot-restore`, `uploads-restore-backup` under `storage/app/private` | 24 hours |
| Content revisions | `content_revisions` table | 90 days, always keeping the newest 10,000 rows |

```bash
php artisan snapshot:prune                       # defaults above
php artisan snapshot:prune --stale-hours=1 --keep-pre-restore=1
```

`snapshot:prune` is scheduled daily in `routes/console.php`; the scheduler must be running (`php artisan schedule:run` via cron). The Migration tab shows the size of each category and a **Clean transient files** button that runs the same prune.

Named snapshots in `storage/app/private/snapshots` are never pruned. Delete them by hand.

### Storage root

Every path above hangs from `config('cms.snapshots.root')`, which defaults to `storage/app/private` and can be overridden with `CMS_SNAPSHOTS_ROOT` in `.env`. All path helpers on `SnapshotService` (`snapshotsDirectory()`, `pendingUploadsDirectory()`, `downloadsDirectory()`, `workDirectory()`, `restoreDirectory()`, `uploadsRollbackDirectory()`) derive from it; never build these paths by hand.

`cms.snapshots.maintenance_mode` (`CMS_SNAPSHOTS_MAINTENANCE_MODE`, default `true`) controls whether a restore wraps itself in `artisan down` / `up`.

The base `Tests\TestCase` points the root at a fresh temp directory per test, deletes it afterwards, and disables maintenance mode, so the suite never touches a developer's real snapshots nor takes the local site down while it runs.

## Extension Points

- `App\Services\Snapshots\SnapshotService` — export, inspect, restore. Add new checks inside `buildReport()`.
- `App\Services\Snapshots\SnapshotReport` / `SnapshotRisk` — report and risk value objects shared by CLI and UI.
- `App\Services\Snapshots\SnapshotHousekeeping` — pending uploads, usage measurement, prune.
- `App\Http\Controllers\Admin\DeveloperToolsController` — admin flow.
- Tests live in `tests/Feature/DeveloperToolsSnapshotTest.php` and run on SQLite in memory, so `pdo_sqlite` is required locally.

## Feature lifecycle on restore

Full editorial versions, review requests and review comments travel with content, separately from the
hash-only revision ledger. See [../admin/editorial-workflow.md](../admin/editorial-workflow.md). Recoverable drafts and shared preview
links are local and cleared on restore. Older archives without version/review tables clear those tables
at the destination rather than attaching old records to reused content ids. The same rule applies to
submission notes when importing a snapshot that predates them.

Queue jobs are excluded. Restored submissions with queued, sending or retrying notifications become
failed with a reset-queue diagnostic and a cleared delivery token; the inbox offers a manual retry.
Already notified submissions remain sent. This also prevents stale destination jobs from claiming
restored notifications with the old generation token.

## Import validation and portable operation history

Checksums detect changed bytes against the archive's declared checksums; they do not authenticate an
archive that an operator can edit and re-checksum. Import therefore applies destination policy again:
Page/Post content normalization and HTML sanitization, media references and disk-relative media paths,
upload MIME/extension/size limits, and inert SVG validation. Invalid content causes the database
transaction to roll back; rejected upload bytes are checked before any replacement. A structurally
compatible old archive can still require correction when its uploads violate the destination's current
upload policy. Inspect the actual archive, not just its declared application version.

Media and localized metadata import before content regardless of a legacy manifest's table order.
Operation receipts (`mcp_operation_receipts`, `editorial_operation_receipts`,
`submission_operation_receipts`, `custom_code_receipts`) are installation-local: export excludes them,
import ignores even explicitly injected archive entries, and restore clears local receipts. Auth/session,
password-reset and preview tables follow the same import exclusion contract. Account credential
fields and Custom Code permission are scrubbed even in a re-checksummed archive; a partial archive
cannot preserve a local Custom Code grant.

Submission events and notes are portable history. Unknown event actors become null; an archive predating
submission events clears destination events that would otherwise attach to reused submission ids.
Every restored notification loses its claim and generation token. Already sent rows remain sent;
queued/sending/retrying rows become failed and require an explicit retry. Existing destination jobs are
not purged by content restore, but their old tokens cannot claim restored rows. A worker rehearsal covers
both reused pending and sent ids: no stale delivery occurs, and only explicit pending retry creates a new
queue job and sends once.

Custom Code payloads and authored history travel, but snippets restore inactive, with no active version
pointer and a new generation. The `restore_inactive` event belongs only to snippet audit; no content
revision marker is added. Destination profile and the local operational kill switch are deployment
configuration and remain local. Review and explicitly activate an exact code version only after the
restored destination, identities and rules have been checked.

This is content recovery, not coordinated deployment rollback. Database commit precedes public-file
replacement; a filesystem failure after that commit requires recovery from the pre-restore backup and
inspection of files. See [update and coordinated recovery](update-recovery.md) for the tested code,
native database and file recovery procedure and its limits.
