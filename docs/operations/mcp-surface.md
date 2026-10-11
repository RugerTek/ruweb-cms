# Keeping the MCP Surface Complete

**Rule: anything an editor can do in the panel, an agent can do through MCP, with the same shared operation.** A
feature without its MCP surface is unfinished. In the `essential` profile it is worse than unfinished: there is no
Live Editor, header/footer screens answer 404 and collections have no CRUD by default, so MCP is often the *only*
editor ([admin-profiles.md](../concepts/admin-profiles.md)). Tool semantics live in [mcp.md](mcp.md); this page is
the checklist for adding to them.

`tests/Feature/McpSurfaceContractTest.php` fails the suite when the mechanical parts are missing (a tool without a
handler, an unclassified read, a tool missing from the [mcp.md](mcp.md) table, a collection without operations, a
writable setting without a default). The judgment parts below are yours.

## What Each Kind Of Change Needs

| You add | MCP work |
|---|---|
| A page template | Declare **every** field in `config/cms.php` `templates.<key>.schema` (lists use `*`, flags `translatable` / `preserve` / `remap` / `localized_media` / `rich_text`). `cms_schema`, the `template_key` enums, the unknown-field check and the schema example all derive from it. `McpAgentOutcomeTest::test_every_template_schema_example_creates_a_valid_draft` proves the example saves. |
| A section or field in a template | Add its paths to that schema. Bearer writes reject undeclared paths, so a field missing from the schema is a field agents cannot write. |
| A collection | The full [collection checklist](#a-new-collection). |
| A public form | Register it in `cms.forms`; the `submissions_*` `form` enum derives from it. Its labels and notification settings are schema fields of the section that renders it. |
| A setting (layout, contact, SEO…) | Default in `App\Support\SettingDefaults`, validation rule in `SettingOperations::update()`, key in `cms.mcp.settings_writable_keys` (and in the localized list in `SettingOperations` if it varies by language). In `essential` the allowlist is the only way to edit header/footer fields. Secrets and transport credentials never go in the allowlist. |
| A taxonomy type (e.g. tags) | Add it to `cms.taxonomy_types`; `taxonomies_*` enumerate it. |
| Any other panel action | A shared operation and a tool, following the [tool checklist](#a-new-tool). |

## A New Tool

1. **Shared operation** in `app/Services/<Area>/<Name>Operations.php`, used by the panel controller *and* MCP. It
   receives the actor explicitly, authorizes before reading or writing, runs inside
   `ContentRevisionLedger::transaction()` (one transaction = one restorable version) and checks the revision with
   a fingerprint that ignores timestamps and list counts.
2. **Controller method** in `McpController` or a dedicated `Mcp<Area>Controller`. Updates and deletes *require*
   `expected_revision`; creations accept `operation_key` through `OperationReceipt`. Answer `{data}`; add
   `warnings` when submitted input was discarded or side effects happened (`SanitizationNotice`,
   `ContentDeletion`); deletions answer `{data: {deleted: {…}}}`.
3. **`app/Mcp/ToolRegistry.php`**: name `<resource>_<verb>`; the description states role, credential scope and
   revision needs; every value from a registry (`config/cms.php`) is an `enum`.
4. **`McpServerController::resolveHandler()`**: one handler, strip route ids with `without()`.
5. **`app/Http/Middleware/EnforceMcpScopes.php`**: reads go in `READ`; writes a draft-only key may make go in
   `DRAFT` (plus the draft-row check when they edit an existing record). Anything else is full-scope only, which is
   the safe default.
6. **`routes/mcp.php`**: the REST mirror. If the tool is in `DRAFT`, extend the draft-only REST patterns in
   `EnforceMcpScopes` too.
7. **Tests** in `tests/Feature/Mcp*Test.php`: success, stale revision (`-32009`), validation (`-32602`), refusal by
   role and by scope (`-32003`), and both profiles when their screens differ.
8. **Docs**: the tool table and semantics in [mcp.md](mcp.md), then `CHANGELOG.md` (`changelog-version`).

## Conventions Every Write Keeps

| Concern | Contract |
|---|---|
| Concurrency | `revision` on every read; `expected_revision` on every change; stale → `-32009`, nothing written. |
| Retries | `operation_key` on creations; same key + same input returns the same record; different input → error. |
| Partial input | Patches merge objects, replace lists whole, `null` removes a key, omitted keys survive. |
| Discarded input | Never a silent success: `warnings` with `code`, `field` and what was removed. |
| Errors | `-32602` validation (message names the fields), `-32009` conflict, `-32003` permission refusal (HTTP 200, `error.data.reason`), `-32601` unknown tool. `-32603` only for real failures. |
| Content rules | Media by id, root-relative internal URLs, publication only through publication tools, public queries filter by publication state ([content-model.md](../concepts/content-model.md)). |
| Deletion | Permanent, revision-guarded, and reports what it changed elsewhere (menu links removed, translations kept, children moved up). |

## A New Collection

A collection (products, cases…) is not reachable through MCP by itself. Besides the
[collection guide](../content/collections.md):

1. Register it in `config/cms.php` `collections` with `'mcp_operations' => '<plural>'` (the tool prefix) and its
   editorial schema in `content_schemas`. `cms_capabilities` reports both.
2. Write `<Name>Operations` with `save()` supporting `draftOnly` and `patch` (see `PageOperations` /
   `PostOperations`), `publish()` and `delete()` returning `ContentDeletion` warnings. The panel CRUD calls it.
3. Add the tools `<plural>_list`, `_get`, `_create_draft`, `_update_draft`, `_update`, `_publish`, `_delete`
   following the [tool checklist](#a-new-tool). `EnforceMcpScopes` resolves the draft row of
   `*_update_draft` from a hardcoded model map: add yours.
4. To give it history, review, preview and translation tools, extend the `['pages', 'posts']` type lists:
   `editorialTools()` and `phaseThreeTools()` in `ToolRegistry`, `McpContentController::model()`,
   `EditorialHistory::model()`, the `whereIn('type', …)` routes in `routes/mcp.php`,
   `ContentSchemaRegistry::forEntity()` and `schema_discovery.content_types` in `AdminCapabilities`.
5. Add the model to `MediaUsage` so `media_delete` never removes media it uses, and to
   `MenuOperations::resolveLinkableType()` if menus may link to it.
6. In `essential` the collection usually has no panel CRUD: these tools are its editor, not an extra.

Verify with a fresh MCP session: `cms_capabilities` → `cms_schema` → create, patch, publish and delete one
record, then read it back.
