---
name: create-form
description: Adds a public form with stored submissions to ruweb-cms — migration, request, controller, routes with the full anti-spam layer, editor section, submissions tab, notifications and tests. Use when the user asks for a contact, quote, signup, newsletter, application or any other public form ("formulario de cotización", "add a newsletter signup").
---

# Create A Public Form

Read [docs/content/forms.md](../../../docs/content/forms.md) — it holds the full contract and the numbered
checklist — plus the "Forms Are Code-Owned" section of
[docs/admin/live-editor-ui-ux.md](../../../docs/admin/live-editor-ui-ux.md).

The contact form is the reference implementation; every step of the checklist names its counterpart to copy.

## Profile First

Run `php artisan cms:capabilities` and read
[docs/concepts/admin-profiles.md](../../../docs/concepts/admin-profiles.md). In `full`, build the Live Editor
section partial (checklist step 8). In `essential`, skip that partial: labels, options and notification
settings are edited through MCP (`pages_update` / `pages_update_draft`), so the section schema must declare
every one of those paths. Everything else — table, request, routes, anti-spam, registry, inbox — is required in
both profiles.

## MCP Surface

Registering the form in `config('cms.forms')` makes its inbox reachable through the `submissions_*` tools with no
new tool. Make sure the section schema lists every administrable path (agents cannot write undeclared paths) and
add the form key to an MCP inbox test. See [docs/operations/mcp-surface.md](../../../docs/operations/mcp-surface.md).

## Before Building

Write the contract down: field list (name, type, required, rules), which labels and which option lists are
administrable, where submissions are stored, whether email notifications apply.

**If the spec does not say whether a field's options are administrable, ask.** Do not infer it from the design,
and never build a generic form builder as a fallback.

## Then

Follow the "Adding A New Form" checklist in [docs/content/forms.md](../../../docs/content/forms.md) in order,
without skipping:

- the full anti-spam layer — CSRF, `<x-forms.honeypot />`, `throttle:forms`, `<x-forms.recaptcha>` — none of
  it optional, and a tripped honeypot redirects as if it had succeeded;
- **one dedicated table** with the real field columns plus the shared metadata block, never generic JSON;
- the registry entry in `config('cms.forms')`, which is what creates the Submissions tab;
- the admin partials `_columns`, `_row`, `_detail`;
- the editor section exposing **only** labels, button text, success message, declared option lists and the
  notification settings, plus the green "View submissions" button linking to that form's listing;
- schema paths flagged `translatable` / `preserve`;
- `lang/es/admin.php` and `lang/en/admin.php` key-for-key, plus `public.forms.<form>.*` fallbacks;
- feature tests mirroring `ContactFormTest`, `AdminSubmissionsTest` and `McpSubmissionsTest`.

## Verify

`php artisan cms:check-conventions` and `composer test`, then submit the form once in the browser and confirm
the row, the redirect with the success message, and the admin detail view.
