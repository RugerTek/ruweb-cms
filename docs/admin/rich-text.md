# Rich Text

> TinyMCE in the admin: named presets, media insertion through the library, server-side sanitisation, public
> rendering. Editor UI rules live in [live-editor-ui-ux.md](live-editor-ui-ux.md).

## Integration

TinyMCE is a project dependency installed through npm and bundled by Vite. There is no
remote script tag and no API key: the build is the open-source one, declared with
`license_key: 'gpl'`.

The bundle is large, so it is code-split: the chunk is fetched only on screens that
contain a `[data-rich-text]` field in the `full` profile, keeping the shared admin bundle small.
The `essential` entrypoint has no dependency on TinyMCE or visual title/link editors. Shared operational
settings use escaped native textareas in essential: existing HTML remains intact in the field and the
same server-side sanitization runs on save. Switching profiles does not convert stored values.

Skin and editable-area styles are inlined into the bundle, so no TinyMCE assets need to
be published into `public/`.

## Enabling A Field

Mark a textarea with the preset it should use:

```html
<textarea name="content" data-rich-text="post-body">...</textarea>
```

The textarea stays the authoritative form field. The editor writes back to it on every
change, so dirty-state tracking, live preview, asynchronous saves, validation and the
leave-warning behave exactly as they do for native controls.

## Presets

Presets live in `resources/js/tinymce-presets.js` and are reused by name. Do not
hand-tune a toolbar inline: add or extend a named preset so equivalent fields behave the
same across projects.

| Preset | Use for | Allows |
| --- | --- | --- |
| `short-description` | hero and section descriptions, running text with no structure | bold, italic, links |
| `post-body` | full article bodies | headings h2-h4, bold, italic, links, lists, quotes, images, embeds, tables, code samples, alignment, horizontal rules |

Rules:

- Short atomic values such as names, labels, kickers and button text stay plain inputs.
- A preset exposes only what the public component renders. Everything else is disabled
  rather than shown as a misleading control.
- Toolbars use sliding mode so they stay one row high inside the narrow editor panel;
  passing an array of toolbar strings would force one fixed row per entry instead.
- When it is unclear whether a field should be rich text, or which actions the design
  supports, ask the project owner before exposing the editor.

## Media Insertion

The image button opens the shared Media Library directly. It never asks for a URL and
never uploads a pasted binary: pasted images are rejected, and the picker inserts a
`figure` with the library file, its alt text and a caption derived from the media title.

Reuse the same entry point for any other feature that needs a file:

```js
window.openMediaLibrary({
    type: 'image',
    onSelect: (media) => { /* media.id, media.url, media.alt, media.title */ },
});
```

## Sanitisation

The editor preset constrains what an author can insert, but the browser is not a trust
boundary. `App\Support\RichTextSanitizer` re-applies the allowlist on the server and runs
from the `Post` model's `content` mutator, so every writer — the admin form, the MCP API,
a seeder, an import — stores the same markup.

The sanitiser:

- keeps only allowlisted elements, and unwraps unknown ones while preserving their text
- removes `script`, `style`, `form` and friends together with their contents
- drops every attribute outside each element's allowlist, which removes `on*` handlers
- accepts only `http`, `https`, `mailto` and `tel` URLs, plus relative and anchor links
- rewrites `href` and `src` values that point at this installation (`config('app.url')`) to root-relative paths, so content survives a change of domain (see [../operations/snapshots.md](../operations/snapshots.md))
- keeps iframes only when the host matches `cms.rich_text.allowed_embed_hosts`
- forces `rel="noopener noreferrer"` on links opening a new tab
- forces `loading="lazy"`, `decoding="async"` and an `alt` on images
- leaves plain-text content untouched, so entries written before the editor still render

## Public Rendering

Render stored rich text through the shared helper rather than echoing it directly:

```blade
{!! \App\Helpers\ContentHelper::richText($post->content) !!}
```

It escapes and line-breaks legacy plain text, and sanitises HTML again at render time, so
content written directly to the database cannot reach the page unfiltered.

Wrap the output in the `prose` classes from the Tailwind Typography plugin so headings,
lists and quotes keep their intended rhythm on the public page.
