# Maintenance Commands

> Recurring operational commands after installation. Setup itself is `php artisan cms:install`.

| Command | Does |
|---|---|
| `mail:test {email}` | Sends a test email to verify outbound mail without using the admin UI |
| `cms:user:reset-password {email?}` | Controlled password reset for an admin or editor from the CLI |
| `cms:media:audit-orphans [--delete]` | Finds media records nothing references. Review the listing before using `--delete` |
| `cms:placeholders [--force]` | Seeds the three placeholders, or restores their files when records exist but files do not (fresh clone, empty `storage/app/public`). Idempotent; `--force` rebuilds from the stubs ([../content/images.md](../content/images.md)) |
| `media:convert-webp [--dry-run]` | Converts eligible raster uploads to WebP |
| `media:generate-variants [--dry-run] [--force]` | Generates responsive variants |
| `media:audit-health` | Compares database state against physical storage |
| `snapshot:create [--name=]` | Creates an `.appbackup` ([snapshots.md](snapshots.md)) |
| `snapshot:inspect {archive} [--json]` | Reports risks without touching the destination |
| `snapshot:restore {archive}` | Restores database rows and uploads |
| `snapshot:prune` | Drops pending uploads and work folders older than 24h and keeps the 3 newest automatic pre-restore backups. Scheduled daily; never touches named snapshots |
| `cms:rewrite-url --from= --to= [--dry-run]` | Replaces one origin with another in every stored text and JSON column, for a domain change made without a snapshot restore |
| `cms:check-conventions [--staged] [--since=]` | Checks the code against the rules in `docs/` |

## Rules

- Run `media:convert-webp` **before** `media:generate-variants`, never in parallel.
- Always run the `--dry-run` pass of `cms:rewrite-url` and read the output before the real run.
- Review the listing before any destructive flag.
