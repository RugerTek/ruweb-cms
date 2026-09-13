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

The current engine does not persist unsaved local drafts to `localStorage`. Draft state is represented by the model status and saved through the normal form endpoint.

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
