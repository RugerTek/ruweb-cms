# MCP Integration

> The agent-facing surface: a JSON-RPC MCP server and a parallel REST API, both under `/mcp` behind the
> `mcp.auth` middleware, sharing the same business rules as the admin.

## Contents

- Surfaces And Authentication
- MCP Server Protocol
- Tools
- REST Endpoints
- Writable Settings Allowlist
- Adding A Tool

## Surfaces And Authentication

| Surface | Base | Use |
|---|---|---|
| MCP server (JSON-RPC 2.0) | `POST /mcp/rpc` | Agents and MCP clients |
| REST API | `/mcp/*` | Direct integrations and scripts |

Both are registered in `bootstrap/app.php` with the `mcp` prefix and middleware group. The group runs
`AuthenticateMcpApiKey` first, then `EnforceMcpScopes` and `Illuminate\Routing\Middleware\SubstituteBindings`, so REST parameters
`{page}`, `{post}`, `{media}` and `{menu}` resolve persisted records. Missing records return 404 only after
valid authentication; missing or invalid tokens return 401. `{key}` for settings remains a plain string.
Policies evaluate the user resolved from the token on both REST and JSON-RPC, independently of any browser
session.

```http
Authorization: Bearer <token>
```

- **Named API keys** for system-to-system use. Each integration has its own hashed key, name, optional
  expiration and scope. The MCP screen displays plaintext only immediately after creation and supports
  individual revocation. Existing keys migrate as full-access keys without changing their value. The
  legacy rotation helper still rotates its prior key; creating a named key preserves other integrations.
  Revoked or expired migrated keys cannot fall back to legacy authentication.
- Scopes apply to both REST and JSON-RPC: `read-only` permits reads; `draft-only` also permits creating and
  updating page/post drafts; `full` permits operations within the owner's role. Draft-only keys cannot
  publish, unpublish an existing published row, delete or reset anything, edit published content live, or mutate
  media, settings, menus, taxonomies or redirects. Draft status is checked
  under a row lock within the mutation transaction, so concurrent publishing cannot bypass the scope.
  Unknown future RPC tools are denied for restricted keys until explicitly classified.
- API keys are the sole authentication method. Legacy OAuth tokens are rejected, and authorization,
  token exchange and OAuth discovery endpoints are unavailable. Replace old OAuth integrations with named keys.

Start with [connect and recover agent access](agent-access.md) for the panel-to-client workflow, compatibility
and recovery procedures. See [account security](../admin/account-security.md) for second-factor enforcement and
credential revocation on password or identity changes.

`mcp` is reserved for the API.

## MCP Server Protocol

JSON-RPC 2.0 with `initialize`, `tools/list` and `tools/call`:

```json
{ "jsonrpc": "2.0", "id": 1, "method": "tools/call",
  "params": { "name": "pages_list", "arguments": { "status": "draft" } } }
```

Clients discover the tool list through `tools/list` rather than hardcoding it.

A controller response with HTTP status >= 400 becomes a tool result with `isError: true` and retains
its diagnostic in `content`. The RPC envelope may still use HTTP 200: clients must inspect `error`
and `result.isError`, not only transport status. Successful controller responses set `isError: false`.
REST keeps its original HTTP status (for example, 422 for a setting outside the writable allowlist, 404 for a
key that does not exist).

Permission refusals — credential scope, account role, custom-code permission or a draft-only edit of a
published record — answer HTTP 200 with JSON-RPC `error.code: -32003` and `error.data.reason`
(`scope_denied` or `forbidden`), plus `scope`, `tool` and, for custom code, `required_scope` and
`required_account_permission`. They are answers, not failures: `-32603` is reserved for real server errors,
and HTTP 200 lets clients relay the refusal to the agent instead of reporting a failed transport. REST keeps
HTTP 403.

Writes that store composition HTML (`pages_create_draft`, `pages_update_draft`, `content_propose`) add
top-level `warnings` when sanitization discarded submitted markup: `code: markup_removed`, the `field`, and
`removed` entries such as `<script>`, `p[onclick]` or `.unknown-class`. The write still succeeds with the
sanitized fragment; reread it before relying on the stored markup.

## Tools

Defined in `app/Mcp/ToolRegistry.php`:

| Group | Tools |
|---|---|
| Discovery | `cms_capabilities`, `cms_schema`, `locales_list` |
| Content / languages | `content_translate`, `content_translation_changes`, `content_translation_acknowledge`, `content_publication`, `content_preview`, `content_preview_revoke`, `content_read_field`, `locales_configure` |
| History and review | `content_versions`, `content_version`, `content_diff`, `content_restore`, `content_propose`, `content_review_request`, `content_review_decide`, `content_review_comment`, `content_reviews` |
| Pages | `pages_list`, `pages_get`, `pages_create_draft`, `pages_update_draft`, `pages_update`, `pages_publish`, `pages_delete` |
| Posts | `posts_list`, `posts_get`, `posts_create_draft`, `posts_update_draft`, `posts_update`, `posts_publish`, `posts_delete` |
| Taxonomies | `taxonomies_list`, `taxonomies_get`, `taxonomies_create`, `taxonomies_update`, `taxonomies_translate`, `taxonomies_delete` |
| Media | `media_list`, `media_get`, `media_update_metadata`, `media_authorize_upload`, `media_usages`, `media_delete` |
| Settings | `settings_list`, `settings_get`, `settings_update`, `settings_update_batch`, `settings_reset` |
| Menus | `menus_list`, `menus_get`, `menus_create`, `menus_update`, `menus_sync_items`, `menus_delete` |
| Redirects and 404s | `redirects_list`, `redirects_create`, `redirects_update`, `redirects_delete`, `not_found_list`, `not_found_forget` |
| Form inbox | `submissions_list`, `submissions_get`, `submissions_assign`, `submissions_set_status`, `submissions_add_note`, `submissions_retry_notification`, `submissions_delete` |
| Custom code | `custom_code_list`, `custom_code_get`, `custom_code_destinations`, `custom_code_save`, `custom_code_activate`, `custom_code_deactivate`, `custom_code_evaluate` |

`tests/Feature/McpSurfaceContractTest.php` fails when a registered tool is missing from this table, has no
dispatcher handler or is a read that restricted credentials cannot call. Keeping the MCP surface complete when a
feature is added is a checklist of its own: [mcp-surface.md](mcp-surface.md).

The surface mirrors the Live Editor. `*_create_draft` and `*_update_draft` only touch drafts. `pages_update` /
`posts_update` patch a record in place like the Live Editor: drafts for anyone with the role, and published
content live (it stays published) **for administrators only**; editors change published content through
`content_propose`, and `content_review_request` / `content_review_decide` apply that exact proposal. Publishing is
its own call and republishing keeps the original `published_at`; no edit ever silently unpublishes a record.
`pages_delete` / `posts_delete` remove a record permanently: menu links to it are removed and its translations
kept, both listed in `warnings`; the base-language homepage cannot be deleted until another page is chosen with
`settings_update` (`homepage_translation_group_id`). Every write runs inside a transaction with a row lock and
records one restorable version of its result.

### Partial content edits

`pages_update_draft.content_json` is a patch, not a full replacement. Omitted map keys survive;
nonempty objects merge recursively, lists replace in full (including `[]` to clear a list), and `null`
removes the corresponding key. At the patch root, `{}`/`[]` are a no-op. PHP decodes nested empty
objects and empty lists identically: use `null` to remove a group, not an empty object. Never send a
stale full document when changing one field. For example, `{"meta":{"description":"New copy"}}`
keeps the SEO title and every section. `{"meta":{"description":null}}` removes just the description.
`posts_update_draft.meta_json` follows the same patch rules; top-level `meta_json: null` clears all SEO.
Scalar post content is replaced only when explicitly supplied. Concurrent patches serialize under a row
lock; an optional `expected_revision` from the latest read prevents stale writes to the same record.

### Locales and pagination

Page/post responses include `locale` and `translation_group_id`. Creation accepts an installed `locale`,
defaulting to the base language, and slug uniqueness is scoped to that language. A new record creates
its own translation group. `content_translate` creates an associated draft for another installed active locale; retries return that existing group/locale record.
Changing an existing record's locale is not supported by draft update.

`pages_list`, `posts_list` and `media_list` accept `page` (integer >= 1) and `per_page` (1–100).
Walk through `meta.last_page`; `meta.total` is the full count. Ordering is newest creation first with
ID descending as a tie-breaker. This is offset pagination over a stable inventory, not a snapshot
across concurrent inserts/deletes. Both REST and RPC use the explicitly supplied page.

Zero-argument tools must serialize `inputSchema.properties` as an object (`{}`), never an array (`[]`); every
schema must remain valid.
After a schema change, reconnect the client and open a fresh session to verify its discovered catalog. `template_key` enumerates `config('cms.templates')`, so an agent cannot invent a template.

Setting an image field with no real asset: select the placeholder matching the slot's aspect ratio by its
`placeholder_key` instead of inventing a URL or leaving the field empty
([../content/images.md](../content/images.md)).

## REST Endpoints

`routes/mcp.php` mirrors the tools: `GET /mcp/pages`, `GET /mcp/pages/{page}`, `POST /mcp/pages/drafts`,
`PATCH /mcp/pages/{page}/draft`, `PATCH /mcp/pages/{page}` (live edit), `DELETE /mcp/pages/{page}`,
`POST /mcp/pages/{page}/publish`, the same shape for posts, plus `/mcp/media`, `/mcp/settings/{key}`,
`POST /mcp/settings/reset`, `/mcp/menus` including `PUT /mcp/menus/{menu}/items`, `/mcp/taxonomies`
(`POST /mcp/taxonomies/{taxonomy}/translations`), `/mcp/redirects`, `GET /mcp/not-found` and
`POST /mcp/not-found/forget`.

Media detail is `GET /mcp/media/{media}`. Update metadata with `PATCH /mcp/media/{media}` or the compatible
`PATCH /mcp/media/{media}/metadata` route. Both accept optional `locale` (query string or JSON body on PATCH)
for an active language. Omitting it edits/reads base text. Secondary alt/title overrides live in
`media_translations`; blank fields inherit base text, and clearing both removes the override. Omitted fields
stay intact. `media_list`, `media_get` and `media_update_metadata` expose the same optional locale via RPC.
Media responses return resolved `alt`/`title` plus the canonical per-language `metadata` map. See
[../content/images.md](../content/images.md#localized-metadata).

Keep REST and MCP aligned in authentication, authorization, serialization and validation — they adapt
the same shared operations used by the panel. Every operation receives its actor explicitly.

## Writable Settings Allowlist

Password-type settings are always returned with `value: null` and `redacted: true`, including for
administrators, through list/get on both REST and RPC. Other settings return `redacted: false`.
An absent secret value in a response is not evidence that the setting is unconfigured.

`config('cms.mcp.settings_writable_keys')` is enforced, not documentation: today `homepage_translation_group_id`
(a translation group whose base-language page is published, the same choice as the panel), `site_name`,
`site_description`, `site_favicon`, `admin_login_path`, `contact_phone`, `contact_whatsapp`, `contact_email`,
`contact_address`, `footer_tagline`, `footer_copyright`, plus the existing public layout/SEO fields:
`site_logo`, `default_social_image`, `header_cta_enabled`, `header_cta_label`, `header_cta_page`,
`header_cta_url`, `header_cta_new_tab`, `contact_location`, `contact_social_links`, `footer_credit_enabled`,
`footer_credit`. This does not include email transport, analytics, credentials, profile or arbitrary configuration. Arbitrary keys are rejected and unknown keys
report `Setting not found.`. `cms.mcp.per_page` defaults listing pagination to 20.

`settings_reset {keys, locale?, expected_revisions?}` restores allowlisted keys to their starter value from
`App\Support\SettingDefaults` (the same list `SettingsSeeder` installs). Without `locale` it resets the value and
removes every localized override; with a non-base locale it only removes that override so the base value applies.
The homepage has no starter value and cannot be reset. A setting's `revision` ignores timestamps: rewriting the same
value keeps it.

## Adding A Tool

Follow [mcp-surface.md](mcp-surface.md): it lists every file a new tool touches (shared operation, controller,
`ToolRegistry`, `McpServerController::resolveHandler`, `EnforceMcpScopes`, `routes/mcp.php`, tests, this doc) and
the conventions each write must keep (revision, operation key, warnings, error codes).

## Deployment discovery

Call `cms_capabilities` (or `GET /mcp/capabilities`) before development work. Its secret-free manifest is
identical to `php artisan cms:capabilities`: selected profile, rollout stability, schemas, structured block
support and section visual compatibility. The tool catalog is identical for full and essential, and this
read is available to read-only and draft-only scopes. Profiles never grant account/credential permissions.
See [administration profiles](../concepts/admin-profiles.md).

## Phase 3 operational contract

`cms_schema` accepts `type: pages|posts` and the registered `template_key` for pages, and returns the exact
schema, locale flags, translation rules and a draft example generated from the schema itself (it saves as-is;
`McpAgentOutcomeTest` checks every template). Bearer page writes may only add paths the template schema declares;
send `null` to remove a legacy key. Registered collections are reported by capabilities; only the shipped Post
collection currently registers MCP operations. Adding another collection requires its schema, explicit operations
and policy in code — see [mcp-surface.md](mcp-surface.md#a-new-collection); there is no arbitrary model/table
dispatch.

`locales_configure` (admin/full scope) installs only a code from the existing catalog and patches installed
activation/publication flags atomically. The base locale always stays active/public. No locale records or content
are deleted, and no configuration file is written. The panel uses the same locale operations.

### Revisions and retries

Pages, posts, menus, settings and media expose `revision`. Send it as `expected_revision` when changing a record;
legacy callers may omit it. The transaction locks and rereads the record before checking. REST returns 409 with
cause, field, expected/current revision and recovery; RPC returns `error.code: -32009` for this conflict.
A revision is a fingerprint of the editorial content, not a counter: it guarantees a write applies to exactly
the content the caller read. Content that returns to an earlier state (A → B → A) has A's revision again, so a
caller still holding A writes without conflict — safely, since nothing it read has changed.
Legacy validation/authorization envelopes remain compatible. Never retry stale replacement content blindly:
read the current record and merge the intended change.

`operation_key` on page/post/menu creation binds actor, operation and canonical input. Creation and its receipt
commit together; concurrent/lost-response retries return the same record identity, with current content. Different
input under the same key returns 409. Receipts survive process restarts. Translation creation is naturally
idempotent on source group + target locale. Publication, metadata and complete menu sync are state-setting writes;
optimistic revisions distinguish stale input. Preview links are expiring bearer capabilities; keep their URLs private.

### Translation, associations and scheduling

Call `content_translate {type,id,locale}`, then patch the new draft through the existing draft tool with translated
copy, slug, SEO and media IDs. The new row keeps the group, media and source hashes and remaps post taxonomies to
available target-locale terms. `categories` on post drafts accepts category IDs from the post language.
`content_translation_changes` returns pages of 100 fields (`page`), immutable source hashes and explicit
before/after text ranges (256 characters by default). Select `field`, `offset` and `length` (at most 8192) to review
long text completely; `source_id` permits focused source reads. `content_translation_acknowledge` requires the
reviewed `base_hash`; source changes or stale revisions reject the acknowledgement without loss. These edits
operate on drafts. Published changes use the proposal/review operations below.

`content_publication {type,id,action,published_at?,expected_revision?}` accepts `schedule` or `unpublish` for
admin/full credentials. Scheduling requires a future instant and an expiry later than publication when present.
Public routes, hreflang and sitemap use the same time-dependent publication query. `content_preview` creates a
1–168 hour URL using the existing noindex preview renderer with the content locale.

### Binary media transfer

Call `media_authorize_upload`, then POST multipart to its `upload_url` with `file`, `ticket`, optional `alt`/`title`
and the **same bearer credential**. Do not place base64 files in tool arguments. Tickets authorize one asset,
expire after 10 minutes and bind both actor and credential. An identical retry returns the existing asset; changed
bytes/metadata conflict. Direct `POST /mcp/media/uploads` also accepts validated batches without a ticket.
Validation, SVG sanitization, optimization and responsive variants use the panel pipeline. Batch failure rolls back
rows and removes newly stored files, including enclosing receipt rollback; deletion removes files only after commit.
URL import is not implemented. `media_usages` lists existing references with page/per_page (1–100) and total metadata; `media_delete` rejects used assets including
drafts, scheduled/localized content, settings and restorable editorial versions.

### Layout and bounded reads

`settings_update_batch {settings,locale?,expected_revisions?}` / `PATCH /mcp/settings` atomically writes only the
reviewed public allowlist. Typed validation and normalization are shared with the panel. Locale overrides apply only
to existing localized keys; contact data remains global. CTA page/custom URI are mutually exclusive; custom URLs on the current site are normalized to root-relative paths by the same panel/MCP write path. Media slots
accept IDs, and rich text is sanitized. Secret values remain redacted on every read.

Menu writes share operations with the panel, validate target language, reject cycles and stale revisions, and
paginate list results (1–100 rows). A menu with items cannot change language; create its localized counterpart and
remap targets. Public header/footer resolve the canonical menu location in the current locale.

Page/post detail and list records bound editorial strings to 8192 bytes and aggregate nested payloads to a budget.
`truncated_fields` and recovery are explicit; original data and revision remain intact. Oversized JSON keys are
reported by hash instead of echoing unbounded names. `fields` on page/post reads selects allowed output fields.
`content_read_field {type,id,field,offset,length}` retrieves string character ranges (up to 8192) or structured item
ranges (up to 100). Recheck revision between reads. Never submit a clipped read as replacement content. REST paths
for generic content operations are `/mcp/content/{type}/{id}/translation`, `/translation-changes`,
`/translation-acknowledge`, `/publication`, `/preview` and `/field`.


## Editorial operation contract (phase 5)

The catalog is identical in `full` and `essential`. Generic editorial operations resolve only `pages` and
`posts`; callers cannot submit PHP model names or database table names. `EditorialOperations` owns the shared
rules for the panel and cookie-free bearer transports. Every operation receives its acting `User` explicitly.

Read-only credentials may call `content_versions`, `content_version`, `content_diff` and `content_reviews`.
These reads never capture history or issue preview links. Draft-only credentials additionally may propose changes
to published content, request review, comment, restore **as a proposal**, issue exact previews and revoke their
own links. Draft-only cannot decide a review, publish, schedule, expire or withdraw publication, nor restore
published content directly as a draft. Full credentials retain the account's role restrictions: only the assigned
administrator decides a review; only administrators alter publication. An administrator may revoke any link.
Legacy draft-update tools still reject published content even with an administrator/full credential.

`content_propose {type,id,changes,expected_revision,operation_key}` validates the same page/post editorial
fields as the existing draft operations, including media IDs, language-specific taxonomies, sanitation and
partial JSON merging. Proposals are immutable complete versions; public content and associations stay intact.
Publication status, publication/expiry dates, locale identity and authorship cannot be patched in `changes`.

`content_review_request` requires `version_id` and `reviewer_id` for the selected exact version. The request
records its author and the proposal's original live base fingerprint. A proposal whose original base already changed cannot request a fresh review against the new base. `content_review_comment` requires `review_id` and `body`;
`content_review_decide` requires `review_id` and `state: approved|changes_requested`. Approval rereads and locks
both the content and review, rejects a changed base, then applies that exact proposal with its associations.
It preserves the current publication status, scheduled date and expiry. Further proposals cannot silently replace
the requested version. Authors of proposals, requests, decisions/comments and committed versions remain traceable.

`content_restore {type,id,version_id,mode,expected_revision,operation_key}` makes its effect explicit:
`proposal` creates a historical-content proposal while preserving the live publication; `draft` replaces the
record with a historical draft and therefore intentionally withdraws a live publication. The latter is the
existing panel restore behavior, and requires an administrator on published records. Neither mode silently
republishes. Available media and taxonomy associations are retained; deleted associations are skipped. Historical
copy is validated against today's schema and uniqueness rules before application. Review and publish explicitly
when the restored draft is ready.

All new editorial mutations except preview issuance require a 64-character `expected_revision` and a durable
`operation_key`. The actor/content/key receipt commits atomically with the result. Identical retries return the
original result even after the base changes; different input under the same key fails with 409. Re-read before
starting another operation. Preview issuance retains optional revision compatibility with phase 3 and creates a
new link on each retry; revoke any unwanted retry link. Active human edit leases reject MCP content application
with 423 rather than silently overwriting a person editing in the panel. Missing inputs return field-level 422
validation diagnostics; stale bases return 409 / RPC `-32009`, with recovery instructions.

`content_preview` accepts optional `version_id`, `hours` (1–168, default 1) and `expected_revision`. It pins the
selected proposal or persisted baseline to an immutable version, so later edits cannot change the preview's
copy or associations. Output includes `link.id`, the exact version and confidential `url`/`preview_url`.
`content_preview_revoke {type,id,link_id,expected_revision,operation_key}` revokes that capability immediately.
The anonymous renderer checks revocation and expiry on every request, uses no-store/private and noindex headers,
and suppresses analytics, editor assets, forms and the admin bar. Token hashes are never returned, URLs are not
stored in operation receipts, and links remain excluded from snapshots.

Each write operation records one version of its result, plus the prior state when history lacked it; internal
saves, media and taxonomy writes within the operation never appear as intermediate versions. History is read
through bounded pages; version/diff field ranges recover long text without clipping revision or
version fingerprints. Never feed a clipped value back as replacement content. REST reads use
`GET /mcp/content/{type}/{id}/{versions|version|diff|reviews}`. Writes use POST with
`propose`, `review-request`, `review-decide`, `review-comment`, `restore`, `preview`, or `preview-revoke`.

`content_publication` additionally accepts `action: expire` and required `expires_at` (future datetime or null
to clear retirement). This administrator/full operation sets retirement without editing content; dates must follow
publication. Public pages, posts and sitemap honor expiry even before the minute scheduler materializes a draft.

The migration adds `editorial_versions.base_fingerprint` and the new `editorial_operation_receipts` table. Existing proposals without a known base are rejected for new review requests/approval: read current content and create a fresh proposal/review instead. Ordinary version history and restoration remain available. `editorial_operation_receipts` contains actor-scoped retry results, not preview URLs/tokens.
Operation receipts are installation-local: snapshots exclude them and restore clears local receipts, even
when an archive supplies those tables. Editorial versions/reviews remain portable content; see
[snapshots.md](snapshots.md) and [update-recovery.md](update-recovery.md).


## Submission follow-up (phase 6)

`submissions_list {form,search?,status?,workflow?,assignee?,notification?,from?,to?,page?,per_page?}` searches the code-owned form inbox. `form` enumerates registered `cms.forms` keys; no arbitrary model/table name is accepted. Search is limited to 200 characters, pages to 100 records. `assignee` is a user ID or `unassigned`; `status: new|read` follows `read_at`, independently of `workflow: new|in_progress|resolved`.

`submissions_get {form,id,notes_page?,field?,offset?,length?}` reads one record and authored notes in pages of 20. Visitor text is bounded to 8192 characters, with `truncated_fields` hashes and complete character length; use `field/offset/length` to recover it. Always reread the revision before editing follow-up. Reads never mark a record read or reserve mail; the human panel detail still explicitly marks it read. Transport tokens, claims, recipients and raw exception strings are excluded from MCP responses. User attribution contains only ID, name and role.

`submissions_assign {assigned_to}` assigns an administrator/editor or clears with null; `submissions_set_status {workflow_status}` changes follow-up state; `submissions_add_note {body}` appends an internal note (nonblank, at most 5000 characters); `submissions_retry_notification` requests a delivery retry. All four require `form`, `id`, current 64-character `expected_revision` and a durable `operation_key`. Account administrators and editors may operate the inbox, but **only full-scope credentials permit follow-up and retry**. Read-only and draft-only credentials may read within those account roles; editorial credentials do not gain notification or workflow permissions. Both profiles expose the same tools. Visitor fields, form fields/validation and notification destinations cannot be changed by these tools.

Panel and transports delegate to `SubmissionOperations` with an explicit actor. Row locks reject stale revisions; actor-scoped receipts serialize identical retries and return their original result. Changing input with an existing key conflicts. Workflow, assignment, notes and retry requests record their actor in `submission_events`; notes also retain their author. Internal notes never enter public pages or outgoing mail. `submission_operation_receipts` is installation-local and excluded from snapshots. `submission_events` history travels with submissions, with valid destination actors or null; restored delivery claims/generations are cleared and pending deliveries become failed, requiring explicit review/retry. See [snapshots.md](snapshots.md).

Retry atomically reserves only failed/skipped delivery generations. Pending and sent generations cannot enqueue again; query the latest delivery state after worker processing. Mail remains controlled by the existing enabled flag, page notification configuration and deployed transport. Delivery uses the real queue with four attempts and backoff; no MCP tool configures a transport or recipient. A provider accepting mail before a connection/worker failure cannot guarantee exactly-once delivery. Failed deliveries expose state/attempts without leaking transport exceptions; repair transport/configuration through its authorized operational path, reread revision and request a retry.

REST mirrors these tools at `GET /mcp/submissions/{form}`, `GET /mcp/submissions/{form}/{id}` and `POST /mcp/submissions/{form}/{id}/{assign|set-status|add-note|retry-notification}`. Missing records return 404, invalid input 422, stale/different retry inputs 409 (RPC `-32009`). Correct the named field or reread and merge before starting a new operation; retry a lost response with the exact same input/key.

Role and scope denials follow the permission refusal contract above: HTTP 200 with `error.code: -32003`, the explicit authorization message and `error.data.reason`; REST returns HTTP 403. Acceptance checks the exact code/message and unchanged data, rather than treating an arbitrary error as an authorization pass. This replaced the legacy `-32603` / RPC HTTP 403 envelopes, which clients reported as internal or transport failures.


## Trusted Custom Code (phase 8)

`custom_code_list`, `custom_code_get`, `custom_code_destinations`, `custom_code_evaluate`,
`custom_code_save`, `custom_code_activate` and `custom_code_deactivate` are identical in both profiles.
They require an administrator with explicit `can_manage_custom_code` and a separate `custom-code`
credential; existing full editorial keys/tokens are denied without mutation. REST mirrors these at
`GET/POST /mcp/custom-code`, `GET/PATCH /mcp/custom-code/{id}`, `GET /mcp/custom-code/destinations`,
`POST /mcp/custom-code/evaluate`, and `POST /mcp/custom-code/{id}/{activate|deactivate}`.
Get supports `versions_page` (20 per page) or an exact `version_id`; active_version is returned separately.

Save creates a complete immutable proposal; activate requires the exact reviewed `version_id` plus current
`expected_revision` and durable `operation_key`. All writes require operation_key, and edits/deactivation
also require revision. Retries recover the original result; changed input or stale revision returns 409 /
RPC -32009. Evaluate takes a stable `target` reference and optional proposal `version_id`, and never executes
code. Account and scope denials return RPC -32003 with `required_scope` / `required_account_permission`
(REST 403). Read the full
[Custom Code contract](custom-code.md) before writing code, evaluating locale/group rules or recovering a frontend.
