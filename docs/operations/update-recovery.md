# Update and recovery

This guide owns updating an existing installation, changing administration profiles and returning to a
known working release. Archive format and compatibility risks belong to [snapshots.md](snapshots.md).
Deployment host setup belongs to [deploy-droplet.md](deploy-droplet.md) or
[deploy-cpanel.md](deploy-cpanel.md).

## Before changing a live installation

Record the exact deployed commit, `VERSION`, database driver, `php artisan migrate:status` and
`php artisan cms:capabilities`. Discover the destination profile as well as the development profile.
`config/cms.php` owns `admin_profile`; it is versioned configuration with no setting or environment
override. Preserve the site's project-specific changes when incorporating starter updates.

Rehearse the update on an isolated copy with the production database driver. Keep outbound email,
external jobs and scheduled work disabled there. A passing SQLite suite does not establish MySQL DDL
or production host behavior. Preserve a verification record with the source/target commits, driver,
commands, observed results and the exact backup set used.

Before taking the recovery set, place HTTP traffic in maintenance mode and stop/drain queue workers,
schedulers and other writers through the host's process manager. `artisan down` does not stop those
processes. `snapshot:create` does not freeze writers or establish one database-and-files point in time.
Keep writers stopped until the update or recovery is validated.

The recovery set must contain:

- The exact old code and dependency/build artifacts, or its commit plus the corresponding lockfiles
  and a verified way to rebuild them. Keep any uncommitted project changes separately.
- A native database backup containing **schema, data and the migrations table**, taken while writers
  are stopped. Use the host's tested backup procedure, including any database-specific objects.
- A matching copy of `storage/app/public` and any other persistent site files that the application uses.
- The existing deployment configuration, `.env`, application key and other installation secrets,
  retained securely outside the portable content archive.
- A named `.appbackup` for content recovery, stored outside the checkout being replaced. Record the
  actual path printed by `snapshot:create`; the root may be overridden by `CMS_SNAPSHOTS_ROOT`.

```bash
php artisan down --retry=60
php artisan snapshot:create --name=before-update
php artisan snapshot:inspect /absolute/path/before-update.appbackup --json
```

Stopping writers and the native database/files backup are host operations in addition to these
commands. Verify that the native backup can restore into an isolated database before relying on it. For a disposable clone on the same MySQL server, export with `mysqldump --set-gtid-purged=OFF` so replay does not try to re-add that server’s existing GTID history. Keep the host’s replication/point-in-time recovery policy for production recovery; do not infer it from a portable content snapshot.
Do not run `cms:install`, `migrate:fresh`, `migrate:refresh` or the general setup/seeding workflow against
an existing live site's data as an update procedure.

## Additive update from 0.47.0

The 0.47.0 release commit is `f337e7d`; `663a2e0` records that commit in the changelog. The update path
through phase 9 retains its existing migration files and adds these migrations:

| Migration suffix | Added schema |
|---|---|
| `120000_create_mcp_operation_receipts_table` | `mcp_operation_receipts` |
| `130000_create_submission_operation_receipts_table` | `contact_submissions.notification_claim`, `submission_events`, `submission_operation_receipts` |
| `180000_create_custom_code_tables` | `custom_code_snippets`, `custom_code_versions`, `custom_code_events`, `custom_code_receipts` |
| `220000_create_editorial_operation_receipts` | Nullable `editorial_versions.base_fingerprint`, `editorial_operation_receipts` |
| `230001_add_custom_code_permissions` | `users.can_manage_custom_code` (default false), OAuth authorization-code/access-token `scope` (default full) |

These filenames all start with `2026_10_10_`. Existing editorial proposals get an unknown (`NULL`)
base; the migration does not guess their original live content. Existing users receive no Custom Code
permission automatically. Review dedicated permissions independently of the selected profile.

While maintenance and stopped writers remain in effect, activate the prepared target code, install
its locked dependencies and build its assets. Keep the existing environment and application key.
Use the production dependency/build procedure for the host, then run:

```bash
php artisan optimize:clear
php artisan view:clear
php artisan migrate --force
php artisan migrate:status
php artisan migrate --force
php artisan cms:capabilities
php artisan cms:check-conventions
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Clear compiled views explicitly before activating the new templates. The phase 10 update rehearsal retained a compiled 0.47.0 public layout after copying source files whose modification times predated the compiled view; without `view:clear`, new Custom Code insertion points were absent until the views were rebuilt.

The second `migrate` should report nothing pending. It verifies Laravel's migration ledger behavior;
it does not mean each migration's `up()` can be called twice. These migrations use unguarded table and
column creation. Re-running an already applied `up()` directly can fail with existing-table/column
errors. MySQL may retain DDL from a failed migration before Laravel records completion. If a migration
fails, keep writers stopped and inspect the actual schema and migration ledger. Restore the coordinated
recovery set or prepare a reviewed repair for that exact partial state; deleting a migration row and
blindly retrying is not a recovery procedure.

Restart long-lived PHP/server processes through the host's process manager so they load the target
code and cached configuration. If workers were stopped, explicitly start them again; `queue:restart`
signals running workers and does not start a service. Restart or re-enable schedulers according to the
host's configuration. Verify public publication/expiry filtering, admin login, representative content,
media files and capability discovery before reopening traffic with `php artisan up`. Check logs and
verify a queued job after workers resume. Run `composer test` in the prepared verification environment
and retain the results; do not use a production test run as a substitute for the rehearsal.

## Full → essential → full

Follow [admin-profiles.md](../concepts/admin-profiles.md). This transition changes deployment behavior,
not stored content, roles or credential scopes. No data conversion or reseeding is needed.

1. Record capability discovery and representative content/associations in full.
2. Set the versioned `config/cms.php` `admin_profile` to `essential` and activate that configuration.
3. Run `optimize:clear`, then regenerate configuration, route and view caches with the commands above.
   Restart workers and persistent server processes; re-enable stopped services explicitly.
4. Discover capabilities again. Verify essential's operational lists, private preview, settings, media,
   submissions and account/MCP access. Editorial mutations through unavailable visual editors return
   404; authorized agent workflows follow their roles and scopes. Compare the stored content and
   associations against the baseline.
5. Before returning to full, supply any specialized section editors required by full and pass
   `cms:check-conventions`. Set `admin_profile` back to `full`, repeat cache regeneration and process
   restarts, then verify the full editors and unchanged stored content.

A snapshot does not switch this profile. Preserve the intended profile in the project's deployment
configuration when updating or restoring code.

## Old snapshots into newer schemas

Always inspect before restoring. `newer_snapshot`, `unknown_migrations`, `missing_tables`,
`missing_columns` and `required_columns_missing` block restore and cannot be bypassed. Fix the code/schema
mismatch first. `older_snapshot`, `diverged_history`, `unknown_history`, `legacy_format`,
`unknown_origin_version` and `driver_mismatch` require explicit acceptance when reported. Read the
details and accept each applicable warning by key; do not use blanket `--yes` in an exploratory recovery.

```bash
php artisan snapshot:inspect /absolute/path/archive.appbackup --json
php artisan snapshot:restore /absolute/path/archive.appbackup --accept=older_snapshot
```

The example only accepts `older_snapshot`; any other reported warning still requires its own
`--accept=<key>`. Inspection is read-only. Restore replaces archive tables' rows and public uploads;
it is not a merge. It preserves the destination schema and migration ledger. A missing old column takes
the destination default or nullable value; already recorded data migrations do not run again.

Examples to consider for archives older than 0.47.0 include multilingual backfills/source hashes,
SEO normalization, setting insertions, submission notification backfill and legacy MCP key conversion.
Defaulted columns cannot recreate all of their intended transformed data. `notified_at` rows are
normalized to sent during current restore, but that does not establish that all older backfills have
been replayed. For those archives, prefer an isolated checkout/schema matching the archive, restore
there, then update forward through migrations. Inspect the resulting newer archive again before
moving it to the destination. Review credentials separately: portable snapshots intentionally invalidate
installation credentials rather than reproducing legacy MCP access.

For a 0.47.0 archive, a current destination supplies unknown proposal bases and false Custom Code
permissions; operation receipts are installation-local retry state. Imported notification deliveries
cannot rely on an old queue. Review failed delivery states before manual retry. Custom Code requires
explicit destination review and activation after restore. Consult [snapshots.md](snapshots.md) and
[account-security.md](../admin/account-security.md) for the current lifecycle guarantees.

Even a same-version snapshot is a content recovery artifact, not an exact operational rollback:
sessions, credentials, queue jobs, cache, recoverable drafts and shared preview capabilities do not
travel as live state. The automatic pre-restore snapshot does not add schema or source code recovery.
Database restore commits before public files are replaced; a later filesystem failure needs inspection
and coordinated recovery. A returned failure must not be assumed to have left every component unchanged.

## Recover an unknown or stale proposal base

After the nullable `editorial_versions.base_fingerprint` migration, a legacy proposal remains readable,
but requesting review or approving it returns HTTP 409 (RPC `-32009`) when its original base is unknown
or differs from the current base. Do not populate that column with today's fingerprint to bypass the
guard, and do not use a draft restore to recover a proposal for live content: draft mode replaces the
record and withdraws publication.

Use the authorized editorial operations documented in [mcp.md](mcp.md):

1. Read the current content/revision and the exact old version/diff. Recover bounded field ranges if
   the response is clipped; never feed a clipped value back as replacement content.
2. For changes merged with current content, call `content_propose` with the intended changes, the
   latest `expected_revision` and a fresh `operation_key`.
3. To recover the historical payload itself, call `content_restore` with its `version_id`,
   `mode: proposal`, the latest `expected_revision` and a fresh `operation_key`. This validates the
   old payload against the current schema and creates a **new** proposal with a known current base.
   It preserves the live record, publication dates and associations until an explicit approval.
4. Read/preview the new exact version. Request a new review using that new `version_id` and assigned
   administrator `reviewer_id`; decide the new review explicitly. A later content change may still
   produce 409; reread, merge and create another proposal instead of changing the fingerprint manually.

Draft-only credentials may recover as a proposal; they cannot approve. Account roles still apply.
Retry a lost response with the identical input and operation key. Start a new key for a changed request.

## Real rollback to the old release

Rolling back code alone leaves the newer schema/data in place. `migrate:rollback` reverses a batch and
may drop proposal bases, receipts, events, custom code and permissions; it does not restore the previous
rows, files or external delivery state. Do not substitute it for the pre-update recovery set.

1. Keep HTTP traffic in maintenance and stop all writers, queues and scheduled work. Preserve a separate
   copy of the failed/current state for diagnosis and recovery of post-backup changes.
2. Restore the exact old code/dependencies/build artifacts and the matching native database backup,
   including its old schema and migrations ledger. Restore the matching persistent files and retained
   environment/application key. Follow the host's verified database restore procedure; avoid importing
   over newer tables in a way that leaves unrelated new schema behind.
3. With the old code active, clear caches, regenerate configuration/routes/views and verify
   `migrate:status` and `cms:capabilities` against the recorded old baseline. Do not run the newer code's
   migrations against the restored database.
4. Restart persistent servers/workers with the restored release and re-enable scheduling deliberately.
   Assess restored queue jobs before allowing delivery: email or another external effect already sent
   after the backup cannot be unsent by restoring a database and might be repeated by restored jobs.
5. Verify public content, media, login, the expected profile and logs; reopen traffic only after these
   checks pass. Account for edits made after the backup before treating recovery as complete.

To recover only content while retaining the newer release, use the inspected snapshot path instead.
That is a different operation with the defaults, credential resets and migration-history limits above.
