---
name: changelog-version
description: Maintains CHANGELOG.md and VERSION per commit in ruweb-cms using SemVer, including moving Unreleased bullets into a versioned entry and filling the SHA of the previous one. Use when preparing a commit, writing changelog entries, or bumping the version ("actualizá el changelog", "bump the version").
---

# Changelog And Version

Per-commit changelog with SemVer (`MAJOR.MINOR.PATCH`, released as 1.x). Entries are written in Spanish, like the
rest of `CHANGELOG.md`.

## Bump Rules

- **MAJOR** — only when the project owner decides to break the starter's contract for its forks.
- **MINOR** — the commit adds or changes product behaviour: UI/UX, modules, endpoints, MCP tools, features.
- **PATCH** — fix, refactor with no functional impact, docs, chores, CI, deploy, styles.
- In doubt, PATCH.

## `VERSION`

One line, semver, no `v`. `config('cms.version')` reads it at runtime and snapshots store it in their manifest
to validate compatibility on restore. **Every new versioned changelog section must ship with `VERSION` set to
that same version in the same commit** — `VersionFileTest` fails otherwise.

## Flow

**Work not being committed yet:** leave the bullets under `## Unreleased`. Do not create a version section.

**Work being committed now:**

1. Compute the bump from the last **closed** version, not from what is in `Unreleased`.
2. If the previous versioned entry still has no SHA, complete its header with the real SHA of that commit.
3. Move the `Unreleased` bullets into a new section `## <version> - <YYYY-MM-DD>` (no SHA), 2–6 impact-oriented
   bullets. Optional `Added` / `Changed` / `Fixed` / `Docs` groupings.
4. Update `VERSION` to the same value.
5. Include both files in the commit.

The `.githooks/post-commit` hook fills that SHA automatically and leaves the edit uncommitted for the next
commit — the same convention, done for you.

## Invariants

- Nothing already committed stays in `Unreleased`.
- At most **one** entry without a SHA, and it must be the newest.
- Header format: `## <version> - <YYYY-MM-DD> (<short-sha>)` once resolved.
- No future work, no "v1" planning.
