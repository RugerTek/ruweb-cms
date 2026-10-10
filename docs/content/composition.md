# HTML composition

Use the existing `Page` entity. Choose `html` for a single fragment inside the public header/footer,
`empty` for a canvas retaining the base document/SEO/locale/hooks, or `composed` for an ordered block list.
See [templates.md](templates.md) for shapes, tokens and registered components. Both administration profiles
expose the same MCP operations. These templates declare `visual_editor: false`; full links to history and
compatibility information instead of converting the fragment into generic visual fields.

## Safe fragment contract

`sections.content.html` is a complete fragment in one locale. The DOM parser admits section/article/div/span,
paragraphs, headings h1–h6, line/section breaks, emphasis, links, lists, blockquotes, figures, tables and
pre/code. Scripts, styles, forms and controls, embedded documents, SVG/MathML and active namespaces are
removed. The renderer never interprets PHP, Blade or JavaScript stored in content.

Common attributes: allowlisted `class`, safe `id`, `title`. Links admit `href`, `_blank` with generated
`noopener noreferrer`; URL schemes are HTTP(S), mailto and tel, plus root-relative paths and anchors.
Own-domain links normalize to root-relative paths. Images admit only `data-media-id` and `alt` plus common
attributes. Table cells admit validated `colspan`/`rowspan`, and headers admit `scope`. Inline styles are
not admitted. The exact tags, attributes, classes and limits are exposed by `cms_capabilities.composition`.

Limits: 200,000 bytes, 5,000 DOM nodes and nesting depth 40 per HTML fragment; at most 60 blocks. Inputs
beyond these limits fail validation. Every write, proposal, restore and preview uses the same normalization;
public render sanitizes again for historical/imported content. This contract does not expand the existing
rich-text sanitizer.

Managed media persist as `<img data-media-id="42" alt="Description" class="cms-media">`. IDs must exist
on editorial writes. The renderer uses the standard responsive-image component, locale metadata, srcset
and intrinsic dimensions. Current content, recovery drafts, versions and proposals protect referenced media
from deletion. Media URLs and raw `src`/`srcset` do not persist in fragments.

## Composition and locale review

List position defines order. Every block has a caller-supplied unique stable `id`, `type`, `version: 1`,
and `is_visible` (defaults true). Reordering replaces the list and preserves identities. HTML blocks carry
`html`; structured/interactive blocks carry a registered `component` and validated `params`. Unknown
components, keys, versions or parameter shapes fail. Component registration and behavior live in the repo.
The shipped `callout` and `accordion` are reusable examples; all visible copy comes from params.

Each localized Page owns its fragment/list and localized URL/SEO. Translation copies the source structure;
translate admitted HTML/component copy while preserving IDs, media references and behavior settings unless
a deliberate localized layout or asset is required. Review the whole fragment, never promise a diff per HTML
node. HTML fingerprints use the whole normalized fragment (including admitted markup). Structured/interactive
block fingerprints extract registered copy paths by stable ID; order, visibility, media and behavior settings
do not invalidate translated copy. Published edits use proposals and exact review with
revision/conflict checks, previews, approval and restore through the existing editorial operations.

## Styling and accessibility

The documented `cms-*` vocabulary ships in the normal frontend build. Copy, layout tokens, block order and
registered component params can change without rebuilding assets. Arbitrary Tailwind classes stored only in
the database are discarded. Additional custom CSS/JS belongs to the separately authorized Custom Code
surface planned for phase 8; it is not executable editorial HTML.

Automatic validation covers structure, supported URLs/attributes, media IDs, component params and limits.
Agents/operators still review heading hierarchy, useful link labels, alternate text, keyboard interaction,
contrast and desktop/mobile layout. HTML fragments cannot create forms or endpoints. Reusing a form requires
an explicitly registered repository component preserving its validation, destinations and full anti-spam
layer; no form component is shipped by these three optional templates.
