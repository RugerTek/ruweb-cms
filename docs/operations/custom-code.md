# Custom Code

Custom Code manages **trusted browser code**, independently of sanitized editorial HTML. It never evaluates PHP
or Blade. Browser code is visible to visitors: do not put credentials, private keys or server secrets in snippets.
Validation checks fragment/wrapper structure, types, positions and a 65536-byte limit; it does not prove JavaScript
safe. Both `full` and `essential` expose the same operational panel and MCP catalog.

## Permission and first grant

An administrator grants `can_manage_custom_code` explicitly in **Users**, including to their own account.
Every existing/new user starts without it; an editor cannot use it even with a stored flag. Browser actions
require the administrator role and that flag. Agents additionally require a separately issued **custom-code**
API-key/OAuth scope. Existing `full`, `draft-only` and `read-only` credentials never inherit this permission.
Revoking the account flag immediately denies subsequent operations without deleting snippets.

The MCP connection screen offers the dedicated scope only to permitted administrators. OAuth requests for
`scope=custom-code` always show explicit consent, ask for credentials and honor existing second factor; an
active browser session cannot silently authorize code. Existing OAuth codes/tokens migrate to `full`.
A custom-code credential retains ordinary discovery/reads, but cannot mutate editorial content.

## Versioned operation

`GET /admin/custom-code` lists snippets; creating or saving creates an immutable **complete** version containing
name, description, `type: html|css|javascript`, `code`, `position: head_end|body_start|body_end`, `sort_order`
and `rules`. Saving never changes the active version or its rules/position/order. Activate an exact `version_id`
after evaluation. Activation revalidates its full payload and fingerprint. Desactivation keeps its historical
active pointer and versions, with state `inactive`; a new snippet has state `draft`. Historical restoration
means reading an old version, saving its complete payload as a new proposal and explicitly activating it.

Every write requires an actor-scoped durable `operation_key`. Writes to an existing snippet also require its
64-character `expected_revision`, which includes the generation changed by every proposal/activation/deactivation.
Transactions lock the actor and snippet, check current permission and reject stale input (409). Identical retries
return the original receipt, even after later changes; changed input under the same key conflicts. Read again before
starting the next operation. Version author, activation author, exact fingerprint and events remain traceable.
Detail reads paginate versions in pages of 20 (`versions_page`), support an exact `version_id`, and retain a
separate `active_version`. List pages contain the latest version metadata, up to 100 snippets per page; code is omitted with bytes/hash and explicit exact-version recovery. Events are bounded
to the last 100 records. Code is returned complete within the validated 64 KiB bound, never clipped for replacement.

CSS accepts a stylesheet body at `head_end`; JavaScript accepts a script body at any declared position. The
renderer owns `<style>`/`<script>` wrappers and rejects wrapper tags, including closing tags. HTML accepts a
fragment without document/script/style wrappers. Code and evaluation output are escaped in the panel.

## Exact destinations and evaluation

`CustomCodeDestinations` owns the registry: `pages`, and entries of `cms.collections` explicitly declaring
`custom_code_public: true`, plus indexes in `cms.custom_code.indexes`. The shipped `blog` index has its own identity and registered public routes. A configured homepage is selected by its Page identity; the unconfigured installation welcome screen has no managed content or Custom Code hooks. New collections must set the flag and assign a target
through `forModel()` only after their public controller has resolved a published record. Do not submit model
class names, URLs, device rules or arbitrary path patterns.

A reference is `{type,id,locale,group:false}`. The locale and row ID select exactly that translation. Explicit
`group:true` on an entity reference selects its translation group; indexes always use exact locale identities.
`rules` has `global` plus `include`/`exclude` reference lists (at most 100 each). Exclusions win over global and
included matches. A deleted inclusion never broadens coverage. A deleted exclusion fails the whole snippet
closed and reports `deleted_destination` until reviewed; an entity ID with the wrong locale cannot match.

Panel and `custom_code_evaluate` use the same resolver, without executing code. Evaluation takes `target` and
optionally an exact proposal `version_id`; reports applicability, payload metadata, diagnostics and operational enabled
state in pages of 20 (maximum 100). Executable code is omitted with bytes/hash and exact-version recovery through get. Known Google Tag, Meta Pixel and Search Console signatures report `dedicated_integration_overlap`.
Their existing dedicated settings stay the single source of identifiers; diagnostics do not deduplicate code.

Public controllers assign a request target only to resolved public Page/Post/index responses. One evaluation
snapshot is shared by all three public-layout insertion points, including HTML/canvas templates. Sort order then
snippet ID determines insertion order, with one insertion per snippet. This does not guarantee execution order
of external asynchronous scripts. Admin, login, OAuth, API, errors, Live Editor and ordinary previews have no
target and receive no snippets. Publication guards remain owned by their public controllers.

## Explicit executable test

The panel's separate POST `/{id}/test-preview` requires current permission/revision, exact version, destination
and `acknowledgement=execute_browser_code`. It warns that real browser code can send external events. It renders
only a separate test document in an iframe with `sandbox="allow-scripts"`, no same-origin/forms/top navigation
privileges and no inherited administrative session. Its source is escaped in the parent. It does not activate a
version, expose a public bearer link or add code to an ordinary editorial preview. This separation protects the
admin document; it is not a promise that arbitrary JavaScript is safe or cannot contact external services.
The operational kill switch also suppresses code in this test. No external tracking snippet is supplied by the starter.

## Recovery and deployment

`CMS_CUSTOM_CODE_ENABLED=false` in `cms.custom_code.enabled` disables all snippets. `php artisan cms:custom-code
disable` additionally writes `storage/app/private/custom-code.disabled`, checked directly on every render even
with cached config. `enable` removes only this marker; a false deployment configuration still wins. `status`
shows the effective state. These commands do not use the database or public frontend. Preserve the marker across
workers/releases until recovery is reviewed; record CLI actions in deployment logs. Individual deactivation
remains an audited operation. Migrate before serving layouts with these hooks; snippets remain off until activated.

## Snapshot contract

New persistent tables are `custom_code_snippets` (including active pointer, state, generation),
`custom_code_versions` (complete immutable payload, author, fingerprint), `custom_code_events` (audit) and
`custom_code_receipts` (actor-scoped retries). New permission fields are `users.can_manage_custom_code` and
OAuth `scope` on authorization codes/access tokens. The existing generic snapshot pipeline discovers snippets/versions/audit automatically. A minimal guard
excludes and clears retry receipts, strips the user permission and restores every snippet **inactive with no
active pointer**, including destination snippets when a legacy archive has no Custom Code tables. It records
`restore_inactive` only in the snippet audit, without altering the content revision ledger. Import never grants
user permission or restores tokens. The local kill-switch marker is operational deployment state,
not portable content. Phase 10 verifies the full schema migration, cross-install compatibility and snapshot portability; see [its results](../plans/phase-10-results.md) and the [snapshot import contract](snapshots.md#import-validation-and-portable-operation-history).
