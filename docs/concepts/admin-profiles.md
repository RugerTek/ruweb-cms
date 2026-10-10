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
permissions, publication guards, media by id, placeholders, localization and appropriate tests. Structured
blocks are currently the supported format; HTML composition and Custom Code arrive in later phases.

In `full`, sections with fields beyond `heading`, `body` and `is_visible` require a specialized partial.
The first existing partial for a shared section key in registry order is reused, exactly as in the Live
Editor. `cms:check-conventions` reports a missing partial; discovery reports visual compatibility.

In `essential`, specialized partials are optional and agents should not create them by default. Existing
partials remain on disk for returning to full; content does not depend on them. Collections still need
schema/model, validation/operations, policy, public routing and tests; specialized editorial forms are
required only in full. Human operational lists and identity/security interfaces remain necessary.

## Current rollout and transition

Essential is **experimental** in phase 2. Specialized page/post creation, editing, preview rendering and
editor lock endpoints return 404. Essential loads a separate entrypoint without the specialized engine,
feedback or recovery runtime; common form/media controls remain shared. Listings use history links and hide creation
and translation shortcuts. Public output, existing content and MCP operations remain available. The final
operational panel, minimal media interface and status/preview workflows are phase 4; this increment does
not claim that panel complete. Other operational interfaces continue to use the existing implementation.

Before activating changes, discover the destination profile. A custom section delivered only for essential
cannot be activated in full until its missing visual editor is delivered and conventions pass. A profile
change itself never converts or deletes data. Set the versioned config, regenerate configuration and route
caches, restart long-lived workers/server processes and compare discovery plus content afterward.
To revert select full, ensuring its required section editors exist. Snapshots do not change this deployment
choice. Never grant permissions based on the selected profile.
