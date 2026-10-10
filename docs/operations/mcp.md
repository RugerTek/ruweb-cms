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
  publish, unpublish an existing published row, or mutate media, settings or menus. Draft status is checked
  under a row lock within the mutation transaction, so concurrent publishing cannot bypass the scope.
  Unknown future RPC tools are denied for restricted keys until explicitly classified.
- **OAuth 2.0** for external agent platforms needing delegated authorization, through
  `/.well-known/oauth-authorization-server`, `/authorize` and `/token` (`routes/oauth.php`), configured with
  `OAUTH_CLIENT_ID`, `OAUTH_CLIENT_SECRET` and `OAUTH_ALLOWED_REDIRECT_URIS`.

See [account security](../admin/account-security.md) for OAuth throttling, second-factor enforcement and
credential revocation on password or identity changes.

`mcp`, `authorize`, `token` and `.well-known` are reserved slugs, so no page can shadow them.

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
REST keeps its original HTTP status (for example, 422 for a setting outside the writable allowlist).

## Tools

Defined in `app/Mcp/ToolRegistry.php`:

| Group | Tools |
|---|---|
| Discovery | `cms_capabilities`, `cms_schema`, `locales_list` |
| Content / languages | `content_translate`, `content_translation_changes`, `content_translation_acknowledge`, `content_publication`, `content_preview`, `content_read_field`, `locales_configure` |
| Pages | `pages_list`, `pages_get`, `pages_create_draft`, `pages_update_draft`, `pages_publish` |
| Posts | `posts_list`, `posts_get`, `posts_create_draft`, `posts_update_draft`, `posts_publish` |
| Media | `media_list`, `media_get`, `media_update_metadata`, `media_authorize_upload`, `media_usages`, `media_delete` |
| Settings | `settings_list`, `settings_get`, `settings_update`, `settings_update_batch` |
| Menus | `menus_list`, `menus_get`, `menus_create`, `menus_update`, `menus_sync_items`, `menus_delete` |

Writes create or update **drafts**; publishing is its own call, and republishing keeps the original
`published_at`. Updating a published page/post is rejected for every role, including administrators;
it never silently unpublishes the record. Preparing a separate pending version through MCP is not yet
available. Draft checking, partial merge, history and media changes run inside a transaction with a row lock.

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

Zero-argument tools must serialize `inputSchema.properties` as an object (`{}`), never an array (`[]`).
The two tools absent in the initial Codex test both used the invalid array shape; phase 0 repaired the original 22 tools. The current catalog has 36 tools, including deployment discovery; every schema must remain valid.
After a schema change, reconnect the client and open a fresh session to verify its discovered catalog. `template_key` enumerates `config('cms.templates')`, so an agent cannot invent a template.

Setting an image field with no real asset: select the placeholder matching the slot's aspect ratio by its
`placeholder_key` instead of inventing a URL or leaving the field empty
([../content/images.md](../content/images.md)).

## REST Endpoints

`routes/mcp.php` mirrors the tools: `GET /mcp/pages`, `GET /mcp/pages/{page}`, `POST /mcp/pages/drafts`,
`PATCH /mcp/pages/{page}/draft`, `POST /mcp/pages/{page}/publish`, the same shape for posts, plus
`/mcp/media`, `/mcp/settings/{key}` and `/mcp/menus` including `PUT /mcp/menus/{menu}/items`.

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

`config('cms.mcp.settings_writable_keys')` is enforced, not documentation: today `site_name`,
`site_description`, `site_favicon`, `admin_login_path`, `contact_phone`, `contact_whatsapp`, `contact_email`,
`contact_address`, `footer_tagline`, `footer_copyright`, plus the existing public layout/SEO fields:
`site_logo`, `default_social_image`, `header_cta_enabled`, `header_cta_label`, `header_cta_page`,
`header_cta_url`, `header_cta_new_tab`, `contact_location`, `contact_social_links`, `footer_credit_enabled`,
`footer_credit`. This does not include email transport, analytics, credentials, profile or arbitrary configuration. Arbitrary keys are rejected. `cms.mcp.per_page`
defaults listing pagination to 20.

## Adding A Tool

1. Implement the behaviour in `McpController` or a service — validating input and authorizing before reading
   or mutating.
2. Register the definition in `ToolRegistry` with a description precise enough for an agent to choose it, and
   an `inputSchema` that enumerates valid values wherever a config registry exists.
3. Add the REST route in `routes/mcp.php` when the operation belongs there too.
4. Serialize through the existing helpers so both surfaces answer the same shape.

## Deployment discovery

Call `cms_capabilities` (or `GET /mcp/capabilities`) before development work. Its secret-free manifest is
identical to `php artisan cms:capabilities`: selected profile, rollout stability, schemas, structured block
support and section visual compatibility. The tool catalog is identical for full and essential, and this
read is available to read-only and draft-only scopes. Profiles never grant account/credential permissions.
See [administration profiles](../concepts/admin-profiles.md).

## Phase 3 operational contract

`cms_schema` accepts `type: pages|posts` and the registered `template_key` for pages, and returns the exact
schema, locale flags, translation rules and a draft example. Registered collections are reported by capabilities;
only the shipped Post collection currently registers MCP operations. Adding another collection requires its
schema, explicit operations and policy in code; there is no arbitrary model/table dispatch.

`locales_configure` (admin/full scope) installs only a code from the existing catalog and patches installed
activation/publication flags atomically. The base locale always stays active/public. No locale records or content
are deleted, and no configuration file is written. The panel uses the same locale operations.

### Revisions and retries

Pages, posts, menus, settings and media expose `revision`. Send it as `expected_revision` when changing a record;
legacy callers may omit it. The transaction locks and rereads the record before checking. REST returns 409 with
cause, field, expected/current revision and recovery; RPC returns `error.code: -32009` for this conflict.
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
operate on drafts. Proposals against published content remain phase 5.

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
