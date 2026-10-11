---
name: create-template
description: Registers a new page template in the ruweb-cms starter — config entry with sections and schema, the Blade view, editor partials and placeholder media. Use when a page needs a layout the existing templates do not provide ("a landing template", "un template nuevo para la home").
---

# Create A Template

Read [docs/content/templates.md](../../../docs/content/templates.md) and
[docs/concepts/content-model.md](../../../docs/concepts/content-model.md).

## Profile First

Run `php artisan cms:capabilities` and read
[docs/concepts/admin-profiles.md](../../../docs/concepts/admin-profiles.md) before choosing deliverables.
`full` requires specialized editors for custom structured fields. In `essential`, omit specialized Live
Editor partials/forms by default; preserve schemas, renderers, validation/operations, permissions, media,
publication and tests. Read Live Editor UI/UX only when creating or changing a visual editor. Verify public
rendering and operation through shared operations/MCP in essential. Both profiles expose the same MCP tools;
role/scope permissions are independent. In `essential`, agents edit through MCP whatever has no panel editor,
so every editable path must be declared in the schema ([MCP surface](../../../docs/operations/mcp-surface.md)).

## Steps

1. **Name the key** (`landing`, `services`) and list its sections in order.
2. **Register it** in `config('cms.templates')` with `name`, `sections` and a complete `schema` — every
   editable path flagged `translatable`, `preserve`, `remap` or `localized_media`, repeaters as `items.*.field`.
   A template without a complete schema cannot create translations, and MCP rejects writes to undeclared paths:
   the schema is the agent's editor. `cms_schema` builds its example from it and
   `McpAgentOutcomeTest::test_every_template_schema_example_creates_a_valid_draft` proves it saves.
3. **Create `resources/views/templates/{key}.blade.php`**, extending `layouts.public`, reading
   `$page->sections()`, guarding each section with `is_visible` plus a content check, rendering media through
   `<x-responsive-img :media="…">`. No hardcoded copy, no hardcoded asset paths.
4. **Full only: editor partials** for sections that need more than `heading` + `body`, per
   [docs/admin/live-editor-ui-ux.md](../../../docs/admin/live-editor-ui-ux.md): one accordion per section,
   visibility switch, repeater rules.
5. **Seed** a starter page or example content with the matching placeholders
   ([docs/content/images.md](../../../docs/content/images.md)) when the template ships with the starter.
6. **Translations** for new admin labels in `es` and `en`.
7. **Verify**: `php artisan cms:check-conventions`, then render a page with the template and switch a page to
   it in the editor without data loss.

## Watch Out

- A template with no view falls back to `templates.default` silently — check the file name matches the key.
- Sections shared with another template should keep the same key so the editor partial is reused.
- MCP exposes `template_key` as an enum of this config, so a new key is immediately agent-visible; verify with
  `cms_schema` (type `pages`, your key) that every field you render appears there.

## Agent-managed composition exception

Registered HTML/composed formats with `visual_editor: false` use shared operations/MCP in both profiles.
Do not create a source editor or route them to generic heading/body inputs. Provide status, history, preview
and an explicit compatibility notice in full. Their sanitizer, semantic schema, media and translation
contracts live in [docs/content/templates.md](../../../docs/content/templates.md). This exception applies
only to explicitly registered composition formats, not ordinary custom structured sections.
