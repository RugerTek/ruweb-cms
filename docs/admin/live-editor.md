# Live Editor Engine

> The shared editorial shell for content types with side-by-side editing and preview: runtime API, DOM hooks
> and backend contract. What the client may edit and how it must look is
> [live-editor-ui-ux.md](live-editor-ui-ux.md).

## Shared Pieces

```text
resources/views/admin/layouts/editor.blade.php
resources/js/editor-engine.js
resources/views/admin/partials/_editor-footer.blade.php
```

Each content type should provide:

- the form partial
- optional header actions
- editor engine configuration

## `initEditorEngine(config)`

Export: `initEditorEngine(config)`

| Parameter | Type | Description |
|-----------|------|-------------|
| `previewUrl` | `string` | POST endpoint that returns rendered HTML |
| `savedMsg` | `string` | success toast message |
| `errorMsg` | `string` | error toast message |

Behavior:

1. Builds form snapshots.
2. Tracks dirty state.
3. Refreshes preview with debounce.
4. Saves asynchronously using JSON-aware requests.
5. Supports draft and publish actions when configured.
6. Preserves preview scroll position during refresh.
7. Warns before leaving a dirty form.
8. Installs the preview navigation guard before public scripts run, on every refresh.
9. Renews the content editing lease and disables saving if ownership is lost, keeping local edits visible.

The engine persists user-scoped recovery snapshots separately from saved content, in browser storage and a server draft retained for 14 days. Recovery never publishes content. See [editorial-workflow.md](editorial-workflow.md) for versions, reviews, scheduling and preview links.

## DOM Contract

Required:

- `#editor-form`
- `#save-btn`

Required when preview is enabled:

- `#preview-frame`

Optional:

- `#update-btn`
- `#save-draft-btn`
- `#publish-btn`

## Shared Footer Partial

Usage:

```blade
@section('editor-footer')
    @include('admin.partials._editor-footer', ['model' => $model])
@endsection
```

Expectation:

- `$model->isPublished()` should exist or an equivalent helper should be supplied

## Preview Viewports

The shared editor layout provides built-in preview modes:

- desktop
- tablet
- mobile

Content types should inherit these viewports from the shared layout instead of reimplementing viewport controls per entity.

## Backend Contract

Each content type using live preview should provide:

1. a `previewRender(Request $request, Model $model): Response` action that renders unsaved changes in memory
2. an `update()` flow that can return JSON for async save requests
3. a preview POST route

For multilingual content, preview must render using the locale of the model being edited, not the admin UI locale. The preview controller should call `app()->setLocale($model->locale)` before rendering the model's `previewView()` / `previewData()`.

The async save request posts the form with `Accept: application/json`. Forms should include Laravel method spoofing when the route expects `PUT` or `PATCH`.

## Exclusive Editing

Page and post `edit`, `update` and `destroy` routes use `ProtectLiveEditor`. Opening the editor reserves that
record in `content_edit_locks` for 120 seconds; the shared engine renews it every 30 seconds and releases it
with a CSRF-protected beacon on `pagehide`. If a browser disappears, expiry permits another editor to enter.
The lease is shared through the database, survives cache refreshes and is excluded from snapshots.

Another user or browser session receives HTTP 423 with the owner's name and a retry action. Reopening in
the same authenticated session rotates the token, allowing reloads while invalidating the earlier document.
Lease acquisition, renewal, release and saves serialize against the content row in a database transaction.
Old tokens cannot release a replacement lease or save after expiry, even when nobody has taken over yet.
The engine keeps pending edits visible and gives a persistent warning when a lease is lost. Returning from
the browser's back/forward cache also disables saving; reopening fetches the current persisted content.

The editor controller passes `editorLease` to the shared layout and form. The form sends `_editor_lock` on
every save, while the layout provides `window.editorLease` to the engine. Non-editor admin submissions
without a token remain compatible only while there is no active editor lease. These guards apply to the
admin editing flow; programmatic writes through MCP or imports do not acquire a Live Editor lease.

New collection editors should register the same middleware on their editing routes and extend the allowed
content types in `EditorLockController`. Preview renderers pass `isEditorPreview => true` to their public
view so the admin bar and its body padding are omitted. Editor responses forbid framing via both
`X-Frame-Options: DENY` and CSP `frame-ancestors 'none'`; iframe requests are rejected before acquiring a lock.

## Verification

`LiveEditorSafetyTest` covers exclusive ownership, expiry, renewal, token rotation, release, preview rendering
and framing restrictions for pages and posts. Run `php artisan test --filter=LiveEditorSafetyTest` for those
flows and `npm test` for preview navigation, lease loss, pending edits and out-of-order preview responses.

## Shared controls and feedback

`editor-controls.js` owns the keyboard/pointer resize divider, token-backed visual titles and searchable
link combobox. Use `admin.partials._editorial-title`, `_section-copy` and `_link-destination`; do not copy
these controls into template-specific implementations. A combobox search is UI state only: the native
selection holds the semantic reference, and only selecting a result changes content.

Section running-text fields declare a `rich_text` preset in their schema and the same `data-rich-text`
preset in the form. `SectionRichText` sanitizes page writes and public rendering with that preset's
allowlist. Plain legacy content keeps its line breaks. TinyMCE initial normalization emits
`editor:field-ready`, so opening a document alone never makes it dirty or acknowledges translation warnings.

`editor-feedback.js` tracks stale fields by DOM identity, propagates badges through tabs and every nested
accordion, and opens/scrolls/focuses the full path on a blocked save. Structural repeaters must dispatch a
bubbling `change` after add/remove/reorder and keep contiguous names. Feedback serializes
`editor_field_origins[]` to map moved fields back to their original translation paths; the backend validates
those paths before checking changes. Successful saves emit `editor:saved`; failed JSON saves pass the
complete `errors` map to feedback. Empty repeater markers explicitly persist an empty list.
