# MCP Integration

## Purpose

This document describes the MCP surface exposed by the starter:

- authentication models
- transport surfaces
- tool discovery and invocation
- extension points for adding new tools

It should stay focused on the integration contract, not on one specific connector vendor or one project instance.

## Integration Surfaces

The starter can expose two related surfaces:

| Surface | Base URL | Typical use |
|---|---|---|
| MCP Server (JSON-RPC 2.0) | `POST /mcp/rpc` | agents and MCP clients |
| REST API | `GET/POST/PATCH/PUT/DELETE /mcp/*` | direct integrations and scripts |

These surfaces should share authentication and business rules whenever possible.

## Authentication

Typical authentication model:

- `Authorization: Bearer <token>`

Common approaches:

### OAuth 2.0

Useful when connecting external agent platforms that require delegated user authorization.

Typical endpoints:

- `GET /.well-known/oauth-authorization-server`
- `GET /authorize`
- `POST /authorize`
- `POST /token`

Typical environment variables:

```env
OAUTH_CLIENT_ID=...
OAUTH_CLIENT_SECRET=...
OAUTH_ALLOWED_REDIRECT_URIS=https://client.example.com/oauth/callback
```

### API Key

Useful for direct system-to-system integrations.

Example:

```http
Authorization: Bearer mcp_api_key_xxx
```

Guidelines:

- key rotation should invalidate the previous key
- the key should act with the permissions of its owner
- revocation should remove access immediately

## Authorization

The exact permission matrix depends on the project's roles and exposed resources. At minimum, document:

- which roles can read content
- which roles can create or publish drafts
- which roles can mutate settings or menus

## MCP Server

### Endpoint

```text
POST /mcp/rpc
Content-Type: application/json
Authorization: Bearer <token>
```

### Protocol

Requests and responses follow JSON-RPC 2.0.

Example:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "pages_list",
    "arguments": { "status": "draft" }
  }
}
```

### Core Methods

- `initialize`
- `tools/list`
- `tools/call`

## Resource Groups

Projects commonly expose tools for:

- pages
- posts or collections
- media
- settings
- menus

When an agent sets an image field and has no real asset, it should select the placeholder matching the slot's aspect ratio — `horizontal`, `square` or `vertical` — by its `placeholder_key`, instead of inventing a URL or leaving the field empty. See `docs/image-strategy.md`.

The exact tool list should be discoverable dynamically through `tools/list`. Avoid hardcoding assumptions in clients when discovery is available.

## REST API

REST endpoints may remain available for direct integrations. If both MCP and REST exist, keep them aligned in:

- authentication
- authorization
- serialization shape
- validation rules

## Writable Settings Allowlist

If settings can be mutated through MCP, document and enforce an allowlist in configuration rather than allowing arbitrary key writes.

Example:

```php
'mcp' => [
    'settings_writable_keys' => ['site_name', 'site_description'],
],
```

## Extending The MCP Surface

Adding a new MCP tool usually requires three steps:

1. implement the business logic in the controller or service layer
2. register the tool definition in the tool registry
3. route the tool call through the MCP server dispatcher

Typical implementation concerns:

- validate inputs explicitly
- authorize before reading or mutating data
- serialize responses through dedicated helpers
- keep tool descriptions clear enough for agents to choose them correctly

## Scope Boundary

This document should not become:

- a vendor-specific setup note
- a complete API reference for every resource
- a deployment guide
