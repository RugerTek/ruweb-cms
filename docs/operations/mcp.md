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
`AuthenticateMcpApiKey` first, then `Illuminate\Routing\Middleware\SubstituteBindings`, so REST parameters
`{page}`, `{post}`, `{media}` and `{menu}` resolve persisted records. Missing records return 404 only after
valid authentication; missing or invalid tokens return 401. `{key}` for settings remains a plain string.
Policies evaluate the user resolved from the token on both REST and JSON-RPC, independently of any browser
session.

```http
Authorization: Bearer <token>
```

- **API key** for system-to-system use. The key acts with its owner's permissions; rotation invalidates the
  previous key and revocation takes effect immediately.
- **OAuth 2.0** for external agent platforms needing delegated authorization, through
  `/.well-known/oauth-authorization-server`, `/authorize` and `/token` (`routes/oauth.php`), configured with
  `OAUTH_CLIENT_ID`, `OAUTH_CLIENT_SECRET` and `OAUTH_ALLOWED_REDIRECT_URIS`.

`mcp`, `authorize`, `token` and `.well-known` are reserved slugs, so no page can shadow them.

## MCP Server Protocol

JSON-RPC 2.0 with `initialize`, `tools/list` and `tools/call`:

```json
{ "jsonrpc": "2.0", "id": 1, "method": "tools/call",
  "params": { "name": "pages_list", "arguments": { "status": "draft" } } }
```

Clients discover the tool list through `tools/list` rather than hardcoding it.

## Tools

Defined in `app/Mcp/ToolRegistry.php`:

| Group | Tools |
|---|---|
| Pages | `pages_list`, `pages_get`, `pages_create_draft`, `pages_update_draft`, `pages_publish` |
| Posts | `posts_list`, `posts_get`, `posts_create_draft`, `posts_update_draft`, `posts_publish` |
| Media | `media_list`, `media_get`, `media_update_metadata` |
| Settings | `settings_list`, `settings_get`, `settings_update` |
| Menus | `menus_list`, `menus_get`, `menus_create`, `menus_update`, `menus_sync_items`, `menus_delete` |

Writes create or update **drafts**; publishing is its own call, and republishing keeps the original
`published_at`. `template_key` enumerates `config('cms.templates')`, so an agent cannot invent a template.

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

Keep REST and MCP aligned in authentication, authorization, serialization and validation — they are two
front doors to one controller layer.

## Writable Settings Allowlist

`config('cms.mcp.settings_writable_keys')` is enforced, not documentation: today `site_name`,
`site_description`, `site_favicon`, `admin_login_path`, `contact_phone`, `contact_whatsapp`, `contact_email`,
`contact_address`, `footer_tagline`, `footer_copyright`. Arbitrary keys are rejected. `cms.mcp.per_page`
defaults listing pagination to 20.

## Adding A Tool

1. Implement the behaviour in `McpController` or a service — validating input and authorizing before reading
   or mutating.
2. Register the definition in `ToolRegistry` with a description precise enough for an agent to choose it, and
   an `inputSchema` that enumerates valid values wherever a config registry exists.
3. Add the REST route in `routes/mcp.php` when the operation belongs there too.
4. Serialize through the existing helpers so both surfaces answer the same shape.
