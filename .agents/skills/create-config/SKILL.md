---
name: create-config
description: Adds or changes configuration in ruweb-cms — a runtime setting in the admin, a config/cms.php value, or both — with seeder, migration, validation, translations and MCP allowlist. Use when the user wants something to be configurable ("que el cliente pueda cambiar X", "make the image width configurable", "add a setting for").
---

# Add Configuration

Run `php artisan cms:capabilities` first: in `essential` several settings screens are hidden and MCP is how the
value gets edited. Read [docs/admin/settings.md](../../../docs/admin/settings.md). Add
[docs/content/images.md](../../../docs/content/images.md) for media-related values and
[docs/operations/mcp.md](../../../docs/operations/mcp.md) when an agent should be able to write it.

## Decide First

| The value | Goes in |
|---|---|
| The client changes it in the admin | A setting, in the right group |
| Fixed per deployment or per environment | `config/cms.php`, reading `env()` |
| Both (a default in code, an override in the admin) | Config default + setting, with one documented precedence |

Business data reused across the site goes in group `contact` and is read through `App\Support\BusinessContact`,
never with scattered `Setting::get()` calls.

## Steps For A Setting

1. Row in `App\Support\SettingDefaults` (read by `SettingsSeeder` and `settings_reset`) **and** a data migration
   so existing installs get it.
2. Correct `type` and `group` ([docs/admin/settings.md](../../../docs/admin/settings.md) lists the types,
   including `media`, `password`, `social_links`, `rich_text`, `map_point`).
3. `settings_fields.<key>.label` / `.help`, group label and option labels in `lang/es/admin.php` and
   `lang/en/admin.php`, key-for-key.
4. Validation rule where the group is saved (`PersistsSettings` for `contact`, `header`, `footer`).
5. Read it through the shared API; add it to the view composer if a partial needs it.
6. **MCP surface.** Every non-secret value an editor can change in the panel must be writable by agents: add it to
   `cms.mcp.settings_writable_keys`, give it a rule in `SettingOperations::update()` (and the localized key list
   there if it varies by language). Mandatory when the screen that edits it is hidden in `essential`. Never for
   secrets or transport credentials. See [docs/operations/mcp-surface.md](../../../docs/operations/mcp-surface.md).
7. Cover it in the matching feature test and an MCP write/reset test; `McpSurfaceContractTest` stays green.

## Rules

- **Never two settings for one fact.** Several screens may edit the same row; that is the pattern.
- Secrets use the `password` type: encrypted, never rendered back, preserved when submitted blank.
- An empty value must degrade gracefully — hide the element rather than render an empty frame.
- Keep the design generic: no client-specific defaults in the starter.


## Trusted browser code

Custom Code is a separate versioned surface, not a settings field. Read
[docs/operations/custom-code.md](../../../docs/operations/custom-code.md) for browser snippets,
exact locale/group destinations, dedicated account/credential permission and operational recovery.
Discover capabilities and destinations, save a proposal, evaluate its exact version, then activate only the
reviewed version with revision and operation_key. Never place server secrets or scripts in editorial HTML.
