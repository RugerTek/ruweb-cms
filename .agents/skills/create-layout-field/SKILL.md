---
name: create-layout-field
description: Changes what the public header or footer shows in ruweb-cms, or the business contact data behind them — a new field, a second logo, another button, a social network, a shortcut card or a whole new layout region. Use when the user asks to change the header, footer, logo, CTA button, copyright, opening hours or contact details ("agregar el horario al footer", "add a white logo for the hero").
---

# Change The Header, The Footer Or The Business Data

Read [docs/admin/layout.md](../../../docs/admin/layout.md) — ownership map, admin screens, `BusinessContact`
and the extension checklists.

## Profile First

Run `php artisan cms:capabilities`. In `essential` the Header and Footer screens answer 404: the field is edited
**only** through MCP, so the MCP steps below are what makes it editable at all. In `full` they are equally
required, because agents must be able to do what the panel does.

## Decide Who Owns The Fact

| The value | What to do |
|---|---|
| Already stored elsewhere (logo, menu, contact data, a collection) | A **shortcut card**, never a copy |
| New and specific to that region | A setting in group `header` or `footer` |
| New and business-wide (opening hours, a second phone) | A setting in group `contact`, read through `BusinessContact` |

## Then

Follow the matching checklist under "Extending" in [docs/admin/layout.md](../../../docs/admin/layout.md):
default in `App\Support\SettingDefaults` **and** a data migration, validation rule in `PersistsSettings`, `es` +
`en` translations, the ordering array in `HeaderController` / `FooterController`, the view composer variable,
the partial rendering, and a case in `tests/Feature/AdminLayoutScreensTest.php`.

## MCP Surface

1. Add the key to `cms.mcp.settings_writable_keys` (never for secrets).
2. Add its validation rule to `SettingOperations::update()` — the MCP path validates there.
3. If its text varies by language, add it to the localized key list in that same method.
4. Test `settings_update_batch` and `settings_reset` for it. `McpSurfaceContractTest` checks it has a default.

See [docs/operations/mcp-surface.md](../../../docs/operations/mcp-surface.md).

A client-facing button destination always uses the shared `admin.partials._link-destination` partial with
`LinkDestination::resolve()` — page selector first, `Custom` last, "open in a new tab" always visible — never a
bare URL input.

Adding a social network is one entry in `App\Support\SocialNetworks::NETWORKS`; nothing else changes.

## Rules

- An empty value hides the element. **Never make a contact field required.**
- Markup stays in the partials; the client edits data only.
- Update the ownership map in [docs/admin/layout.md](../../../docs/admin/layout.md) and add a changelog entry.
