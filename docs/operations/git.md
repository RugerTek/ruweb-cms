# Git

> Starting a project from the starter, and the hooks this repo ships.

## A New Project From The Starter

```bash
git clone git@github.com:your-organization/ruweb-cms.git new-project
cd new-project
git remote remove origin
git remote add origin git@github.com:your-organization/new-project.git
git push -u origin main
```

Do not fork by default. A fork couples the client repo to the starter, blurs ownership and pull-request flow,
carries history the derived project does not need, and implies an upstream sync model most projects never use.
Fork only when there is an explicit plan to keep syncing.

## Hooks

Hooks live in `.githooks/` and are versioned. `composer setup` enables them; enable them manually with:

```bash
git config core.hooksPath .githooks
```

- **pre-commit** — `php -l` on staged PHP files, then `php artisan cms:check-conventions --staged`. Fast by
  design; it never runs the test suite.
- **post-commit** — fills the SHA of the newest changelog entry that has none, using the commit just created,
  and leaves that edit uncommitted for the next commit (the flow `changelog-version` describes). It never
  amends.

`--no-verify` skips them. The checks are also available as `composer test` (`ConventionsTest`) so CI catches
what a local bypass misses.
