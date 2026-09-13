# Maintenance Commands

## Purpose

This document lists recurring operational commands and how to think about their use.

It should stay focused on maintenance workflows, not implementation history.

## Typical Maintenance Areas

- email delivery verification
- credential recovery or password reset
- orphaned media auditing
- placeholder image seeding and repair
- image optimization and variant generation
- media health auditing
- snapshot transient cleanup

Initial project setup is handled by `php artisan cms:install`. This guide focuses on recurring operational commands after installation.

## Send A Test Email

```bash
php artisan mail:test {email}
```

Use this to verify outbound email configuration without going through the admin UI.

## Reset A User Password

```bash
php artisan cms:user:reset-password {email?}
```

Use this when an administrator or editor needs a controlled password reset from the command line.

## Audit Orphaned Media

```bash
php artisan cms:media:audit-orphans
```

Use this to find media records that are no longer referenced by the application.

If the command supports deletion, review output carefully before using destructive flags such as `--delete`.

## Placeholder Images

```bash
php artisan cms:placeholders
php artisan cms:placeholders --force
```

Use this to seed the starter placeholder images, or to restore them when the media records
exist but their files do not, as happens on a fresh clone where `storage/app/public` is
empty. It is idempotent and keeps existing media records, so references stay intact.
`--force` rebuilds them from the stubs.

See `docs/image-strategy.md` for which placeholder belongs in which slot.

## Image Optimization Workflow

Typical sequence:

```bash
php artisan media:convert-webp
php artisan media:generate-variants
```

Guideline:

- run sequentially, not in parallel

Useful options may include:

- `--dry-run`
- `--force`

## Audit Media Health

```bash
php artisan media:audit-health
```

Use this to compare database state and physical storage state for media assets.

## Operational Guidance

- prefer dry-run modes when available
- review output before destructive operations
- document environment-specific differences outside this file

## Scope Boundary

This document should not become:

- a deployment guide
- a troubleshooting log for one environment
- a changelog of when commands were introduced

## Prune Snapshot Transients

```bash
php artisan snapshot:prune
```

Removes pending snapshot uploads and work folders older than 24 hours, and keeps only the 3 newest automatic pre-restore backups. Scheduled daily; the same action is available from `Developer tools → Migration`. Named snapshots are never touched. See `docs/snapshots.md`.

## Rewrite The Site Origin

```bash
php artisan cms:rewrite-url --from=http://old.example --to=https://new.example --dry-run
php artisan cms:rewrite-url --from=http://old.example --to=https://new.example
```

Replaces one origin with another inside every stored text and JSON column, for installations that changed domain without going through a snapshot restore. Run the dry run first. See `docs/snapshots.md`.
