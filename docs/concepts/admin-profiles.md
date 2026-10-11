# Administration profiles

`config/cms.php` owns `admin_profile`: `full` (default) or `essential`. It is versioned project configuration,
not editorial data, and has no environment override. An invalid value fails startup with an explicit
`cms.admin_profile` diagnostic. Existing installations retain full behavior.

Run `php artisan cms:capabilities` before planning page/template/section/collection work. MCP exposes the
same secret-free manifest through `cms_capabilities` and `GET /mcp/capabilities`. It includes the profile,
stability, interface capabilities, schemas, section compatibility and supported block formats. The MCP
catalog is identical in both profiles. Account roles and credential scopes authorize operations separately.

## Development delivery

Both profiles require typed content schemas, frontend renderers, reusable operations and validation,
permissions, publication guards, media by id, placeholders, localization and appropriate tests. Structured blocks, sanitized HTML and registered composition components are supported. Custom Code uses the same dedicated operational panel and explicit permission in both profiles.

In `full`, sections with fields beyond `heading`, `body` and `is_visible` require a specialized partial.
The first existing partial for a shared section key in registry order is reused, exactly as in the Live
Editor. `cms:check-conventions` reports a missing partial; discovery reports visual compatibility.

In `essential`, specialized partials are optional and agents should not create them by default. Existing
partials remain on disk for returning to full; content does not depend on them. Collections still need
schema/model, validation/operations, policy, public routing and tests; specialized editorial forms are
required only in full. Human operational lists and identity/security interfaces remain necessary.

## Current rollout and transition

Essential's operational panel was accepted in [E2E-04](../plans/phase-4-results.md) (historical evidence; the
current contract is this page and the tests). Both profiles are supported
for structured content and HTML composition. Custom Code has a dedicated panel in both profiles; the implemented MCP editorial, submissions, snippets and recovery workflows share the same server contracts. Release acceptance history is in [phase 11](../plans/agent-first-v1.md#fase-11--candidato-y-publicación-de-100). In
essential, agents edit through MCP whatever the panel does not offer; every feature must ship that surface
([MCP surface](../operations/mcp-surface.md)). HTML/composed templates have an explicit agent-only editing contract in full;
the status, history and preview presentation preserves their content without a generic visual editor.

Essential retains login, account security, users/roles, MCP access management, general settings,
analytics/email integrations, languages, media upload/search/metadata/usage and form submission inboxes.
Developer Tools shows basic system diagnostics; snapshots and advanced changes use authorized CLI
operations. Taxonomy/menu/header/footer editors and page/post editor mutations return 404. Full retains
its existing editorial screens and actions.

Pages, posts and registered collections have read-only operational lists across all locales, with title/slug
search, draft/live/scheduled/expired state, persisted version history/differences and preview. Collection
lists require `viewAny`; status and preview require `view`. A private authenticated GET preview renders
saved content with no caching/indexing, no admin bar/analytics or form submission. Opening status or
preview never captures versions or creates bearer preview links. Editing, translation, publication and
restoration remain agent operations in essential, according to the operations currently available.

Essential uses a separate entrypoint whose dependency graph excludes the Live Editor, its specialized
controls, recovery runtime and TinyMCE. Common form/media/map controls remain available. Rich-text
settings use escaped native textareas and the same server sanitizer; this does not introduce an editorial
CRUD or alter stored content. Navigation and the authenticated public bar hide unavailable actions.

Before activating changes, discover the destination profile. A custom section delivered only for essential
cannot be activated in full until its missing visual editor is delivered and conventions pass. A profile
change itself never converts or deletes data. Set the versioned config, regenerate configuration and route
caches, restart long-lived workers/server processes and compare discovery plus content afterward.
To revert select full, ensuring its required section editors exist. Snapshots do not change this deployment
choice. Never grant permissions based on the selected profile.
