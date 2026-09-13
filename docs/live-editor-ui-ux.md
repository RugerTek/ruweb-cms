# Live Editor UI/UX Requirements

## Purpose

This document is the canonical, reusable visual and interaction contract for every form rendered inside the Live Editor across Ruweb projects. Project-specific implementations may extend it, but must not weaken its requirements.

It complements `docs/live-editor.md`:

- `docs/live-editor.md` defines the editor engine, DOM hooks, preview, and backend contract.
- this document defines how editable content must be organized and presented in the editor panel.

The central principle is progressive disclosure: the client should reach the desired field quickly and see only the content they are currently editing.

## Editor Shell And Responsive Preview

The Live Editor must preserve a clear separation between editing and previewing.

Requirements:

1. On desktop, render the editor as a narrow, full-height panel and use the remaining width for the live preview.
2. Use the current narrow panel width, approximately `24rem` (`384px`), as the minimum editor width.
3. Place a visible draggable divider between the editor and preview so the client can widen the editor when complex content needs more space.
4. Limit the editor panel to a maximum of `50%` of the available editor workspace; the preview must always retain at least the other half.
5. Resize the editor and preview continuously while the divider moves, without reloading either surface or losing form state.
6. Make the resize divider keyboard accessible and expose its current, minimum, and maximum values to assistive technology.
7. The editor panel must scroll independently from the preview.
8. On mobile, disable panel resizing and provide explicit `Edit` and `Preview` modes instead of squeezing both surfaces side by side.
9. Preview controls should support the project's desktop, tablet, and mobile viewport presets.
10. Switching viewport or editing content must not discard unsaved form state.
11. Refreshing the preview should preserve its scroll position whenever possible.

### Preview Navigation Safety

The live preview is an inspection surface inside the current editing flow, not a browser. Links rendered by the public template must never navigate while they are displayed inside the Live Editor.

Requirements:

1. Disable every anchor and image-map link inside the preview, including internal pages, external destinations, hash links, admin-bar links, and links configured to open in another tab.
2. Prevent primary, auxiliary, keyboard, and script-assisted activation from changing the iframe URL, replacing the preview, opening another editor inside it, or creating a new browser tab.
3. Keep the link's normal public appearance so the client can review the design, but do not present it as an interactive preview control.
4. Reapply the navigation guard after every preview refresh and to content introduced dynamically by the preview when applicable.
5. Provide a separate, explicit `View live` action outside the preview for testing real navigation on the public page.
6. Disabling preview navigation must not disable non-navigation interactions needed to inspect the page, such as accordions, sliders, modal previews, or responsive controls.

## Narrow-Panel Layout

The editor form occupies a narrow full-height panel on desktop and a narrow mobile viewport on small screens. Form design must respect that constraint at every breakpoint.

Requirements:

1. Use one content column by default.
2. Never place two or more independent inputs side by side.
3. Never use multi-column card or field grids inside the editor panel.
4. A compact asymmetric row is allowed only when a narrow secondary control is inseparable from a wider primary field, such as:
   - value plus unit
   - value plus prefix or suffix
   - main input plus a short selector or action
5. Allowed asymmetric rows should follow an approximate `20/80` or `30/70` proportion and must collapse to one column when the available width is insufficient.

Independent fields that merely happen to be short are not an exception. They still belong on separate rows.

## Form Navigation

Use a small set of purpose-based tabs when the content type needs them, such as `General`, `Content`, `SEO`, and `Status`.

Requirements:

1. Keep page-section accordions inside the content-oriented tab.
2. Do not use tabs to hide a small number of fields that would be clearer in one short panel.
3. Preserve the active tab while the client works unless navigation is required to reveal an error or warning.
4. Propagate pending warnings and validation errors to their corresponding tab.
5. Keep tab labels short enough to remain usable in the narrow editor panel.

### Origin-Aware Back Navigation

The editor's Back action must return the client to the last valid location from which they entered the editor. It must not always point to one hard-coded destination and must not rely only on browser history.

Required behavior:

- When the client enters through `Edit page` in the authenticated public admin bar, Back returns to that exact public page.
- When the client enters from a backoffice collection or page listing, Back returns to that listing, preserving relevant filters, search terms, pagination, and locale when available.
- When no valid origin is known, Back falls back to the current content type's admin listing.

Implementation requirements:

1. Carry an explicit return location into the editor when the entry link is generated.
2. Preserve the return location through asynchronous saves, full reloads, draft or publish transitions, locale switching, translation creation, and other navigation that remains inside the same editing flow.
3. Accept only internal same-origin destinations or another explicitly trusted application destination; reject unsafe external return values to prevent open redirects.
4. Keep the Back target stable until the client intentionally leaves the editing flow.
5. Use a contextual accessible label such as `Back to page` or `Back to pages`, based on the resolved destination.
6. If unsaved changes exist, apply the normal leave-warning behavior before navigating back.

## Field Presentation And Controls

Every control should reflect the client's editorial intent instead of exposing implementation details.

Requirements:

1. Every field must have a persistent visible label; placeholders are examples or hints, never label replacements.
2. Add concise help text when the effect, expected format, or source of a field is not obvious.
3. Use the shared Media Library selector for media instead of exposing database IDs, storage paths, or raw asset URLs.
   A media field with no asset yet should preview the placeholder whose aspect ratio matches
   the slot it renders — `horizontal`, `square` or `vertical` — so the client sees the intended
   composition rather than an empty frame. See `docs/image-strategy.md`.
4. Use a dedicated visual control for supported text treatments such as highlighted title fragments instead of asking the client to edit markup or tokens.
5. Do not expose raw JSON, internal identifiers, schema paths, or template implementation details.
6. Display validation feedback next to the affected field in plain, actionable language.
7. Keep focus, disabled, loading, success, warning, and error states visually distinct.

## Client-Controlled Surface

Only expose decisions the client is allowed and expected to make.

- Content, links, supported media, and documented presentation options may be editable.
- Fixed icons, structural quantities, layout primitives, technical slugs generated internally, and other template-owned decisions should remain in code unless the content contract explicitly makes them configurable.
- Do not create controls that imply the public layout supports combinations it cannot render correctly.

### Editorial Title Emphasis

An editorial title is one piece of content even when its public presentation uses more than one typeface, weight, color, or emphasis style. Visual treatment must not split one title into fields such as `normal title`, `highlighted title`, `first line`, or `second line`.

Use the inline highlighting interaction established in SAS instead:

1. Render one visual text editor and one persistent `Title` label.
2. Place a clearly labeled `Highlight` action in the field header, aligned to the upper right.
3. Let the client select a word or phrase inside the title and activate `Highlight` to mark only that selection as editorial emphasis.
4. Show the selected fragment with the intended emphasis inside the editor so the result is understandable without reading markup.
5. Preserve the selection when the client presses the action, so clicking the toolbar control does not discard the selected range.
6. Keep the title serialized as one value. A safe internal token representation such as `[highlight]...[/highlight]` may be stored in a hidden input, but tokens and raw HTML must never be exposed as the normal editing surface.
7. Convert only the explicitly supported emphasis token to an allowlisted semantic element such as a styled `<span>` when rendering the public page. Escape all ordinary title text and never render arbitrary client HTML.
8. Synchronize the visual editor and serialized value on every edit so dirty-state tracking, preview, validation, saving, and multilanguage warnings continue to work at the single-field level.
9. Keep line wrapping and responsive line breaks owned by the public template. Do not create separate content fields merely to force a desktop line break.
10. Treat the emphasis as presentation metadata within the title, not as a second title. Translation warnings and validation belong to the complete title field.

The project may define the visual style applied by the semantic emphasis—typeface, italic, weight, or color—but the client selects the emphasized text through the same single-field interaction. If the design supports more than one semantic treatment, each treatment requires an explicitly named, allowlisted toolbar action; a generic rich-text or HTML editor is not implied.

### Context-Scoped Rich Text With TinyMCE

Paragraphs and other running-text fields should use a WYSIWYG editor by default so the client can apply appropriate inline formatting without writing markup. Ruweb projects must install and use TinyMCE as the standard rich-text library rather than implementing ad hoc `contenteditable` editors for ordinary rich text.

This is a context-scoped editor, not an unrestricted document builder. Every TinyMCE instance must be configured for the content and public component it controls.

Requirements:

1. Treat descriptions, introductory copy, explanatory paragraphs, and other running text as WYSIWYG candidates by default.
2. Use a normal text input for short atomic values such as names, labels, kickers, button text, phone numbers, and other content that must remain plain text.
3. Install TinyMCE as a project dependency and integrate it through the application's asset pipeline. Do not depend on an unversioned remote script or expose a third-party API key when a local package is sufficient.
4. Define a minimal preset for each editorial context. A short hero description may allow only paragraph text, bold, italic, and links if links are supported by the design.
5. Disable images, media embeds, tables, headings, font families, font sizes, colors, alignment, source-code editing, and any other control that the target component does not explicitly support.
6. Keep the toolbar short, understandable, and usable in the narrow editor panel and on mobile. Hide unavailable actions instead of displaying disabled or misleading controls.
7. Store only the allowlisted semantic markup produced by that preset. Sanitize rich text on the server and render only the same allowed elements and attributes on the public page.
8. Keep TinyMCE synchronized with its underlying form field so live preview, dirty-state tracking, asynchronous saves, validation, and multilanguage warnings work exactly as they do for native controls.
9. Make validation errors and translation warnings visually discoverable at the editor container and through every ancestor accordion and tab.
10. Preserve keyboard access, visible focus, labels, descriptive help, and the normal editor leave-warning behavior.
11. Reuse named TinyMCE presets across equivalent field types so formatting behavior remains consistent throughout Ruweb projects.
12. If it is unclear whether a field should be rich text or which formatting actions the public design supports, stop and ask the project owner before exposing the editor or additional toolbar controls.

Example: a homepage hero description may use a `short-description` preset with bold and italic, while image insertion remains unavailable because images are managed by the section's dedicated Media Library field.

### Button Destination Selector

Every editable button destination must use a semantic page selector by default. A bare URL input is not the primary control for a client-facing button.

Requirements:

1. Label the control according to its purpose, such as `Button destination`.
2. Populate a select with the website's available internal pages, using their client-facing titles rather than database IDs, slugs, route names, or raw URLs.
3. Store an internal selection as a stable semantic page reference, not as the page's current literal URL. The reference belongs to the translation family or logical page identity rather than to one localized record.
4. Place `Custom` as the final select option.
5. Reveal a manual destination input only while `Custom` is selected; keep it hidden for internal-page selections.
6. Use the manual option for destinations that cannot be represented by the page collection, such as an approved external URL, anchor, telephone link, email link, or other explicitly supported URI.
7. When loading existing content, preselect the matching internal page when the saved value resolves to one; otherwise select `Custom` and show the existing value in the manual field.
8. At render time, resolve an internal page reference to the corresponding translation for the current public locale. One editor selection must therefore produce the Spanish destination on the Spanish page, the English destination on the English page, and so on, without configuring the link again in each translation.
9. Define an explicit fallback when the destination page does not exist in the active locale: normally resolve to the base-locale page and expose the missing translation through editorial controls, or suppress the link if the project requires strict locale isolation. Never generate a broken localized URL silently.
10. Keep internal page references non-translatable. Editing a translated page must display the same logical selection and its automatically localized resolved destination, not a second independent link field.
11. For `Custom`, serialize the validated manual destination separately from internal page references. Preserve an entered custom value while the client temporarily switches to an internal page during the same unsaved editing session, but submit only the currently selected destination mode.
12. Validate and normalize custom values on the server. Allow only the URI schemes and destination forms supported by the project, and prevent unsafe script URLs.
13. Keep dirty-state tracking, live preview, asynchronous saving, validation feedback, and multilanguage behavior synchronized with the effective destination.
14. If the target content type is not a normal page, use a similarly semantic selector for its collection when available; do not make the client copy a public URL manually.
15. Always include an `Open in a new tab` checkbox as part of the destination control, for both internal and custom destinations. Keep it visible without requiring `Custom`, leave it unchecked by default for new buttons, and restore its saved state when editing.
16. Store the new-tab preference alongside the semantic destination as non-translatable behavior so it is configured once for every locale. When enabled, render the link with `target="_blank"` and the appropriate `rel="noopener noreferrer"` protection.

Reference implementation: `resources/views/admin/partials/_link-destination.blade.php` with `App\Support\LinkDestination` (used by the header CTA, `docs/layout-header-footer.md`).

This pattern controls destinations only. Button text remains a separate labeled content field, and whether the public component contains a button remains part of its code-owned layout unless the content contract explicitly says otherwise.

### Forms Are Code-Owned

The Live Editor is not a form builder. Public forms must be designed and implemented in code by default. The full implementation contract, the anti-spam layer and the checklist for adding a form live in `docs/forms.md`; the contact form is the reference implementation.

The code-owned contract includes:

- which fields exist
- field types and order
- required and optional states
- validation rules
- conditional behavior
- submission handling and destinations
- authorization, anti-spam, privacy, and security behavior

The Live Editor must not let a client add, remove, reorder, or change the type or behavior of form fields unless a project explicitly defines and approves a different contract.

The editable surface may expose only:

1. client-facing field labels explicitly declared editable
2. client-facing validation error messages explicitly declared editable
3. options for `select`, checkbox-group, or radio-group fields when the form contract declares those options administrable

Administrable field options must follow the administrable repeater requirements in this document. Each option is an item in the repeater; adding and deleting options uses the standard controls and destructive confirmation flow. Client-facing option labels may be editable, while stable machine values and backend mappings remain code-owned unless the form contract explicitly states otherwise.

When a form stores submissions in an admin collection, its Live Editor section must also provide a clear handoff to that collection:

1. Explain briefly that submitted entries are reviewed outside the page editor.
2. Provide a prominent green button with a right-arrow icon.
3. Use a direct, concrete label such as `View submissions` or `Manage contact submissions`.
4. Link directly to the submission listing for that form, not to the generic admin dashboard.
5. Respect the current user's authorization for viewing submissions.

This navigation control does not make the form structure administrable; it only gives the client direct access to the records produced by the code-owned form.

If an agent cannot determine whether any part of a form should be administrable, it must stop and ask the project owner before exposing that control in the Live Editor. It must not infer permission from the visual design or create a generic form builder as a fallback.

## One Accordion Per Page Section

Every identifiable top-level page section must have its own accordion.

Requirements:

1. The accordion label must clearly identify the page section.
2. All fields belonging to that section must live inside its accordion.
3. Do not leave section fields loose outside an accordion.
4. Do not combine multiple identifiable page sections inside one accordion.
5. Top-level section accordions should be collapsed by default so the client can scan the page structure before choosing what to edit.

### Visibility Control

Every top-level section accordion must include a visibility switch in its header.

The switch must:

- represent the section's `is_visible` state
- communicate visible and hidden states clearly
- remain accessible when the accordion body is collapsed
- operate independently from the accordion trigger
- never expand or collapse the accordion when toggled

Nested repeater items do not receive a visibility switch unless the underlying content model explicitly supports item-level visibility.

## Nested Repeaters

Any collection of repeated, structurally similar items inside a section must be treated as a repeater. Examples include counters, cards, testimonials, benefits, timeline entries, branches, slides, products, and team members.

Each repeater item must have its own nested accordion inside the parent section accordion. Multiple nested item accordions may remain open at the same time so the client can compare or edit related items without losing context.

The interaction hierarchy is:

```text
Page section accordion
└── Repeater item accordion
    └── Fields for only that item
```

For example, a section containing four counters must not render the fields for all four counters at once. The client opens the section accordion and then opens only the counter they want to edit.

Requirements:

1. Keep nested repeater accordions collapsed by default.
2. Show a concise, stable item label in each nested accordion header.
3. Prefer meaningful labels derived from the item, such as its title, label, name, or position.
4. Fall back to a numbered label such as `Counter 1` or `Card 2` when no meaningful value exists.
5. Keep item actions visually associated with the repeater, without exposing every item's fields simultaneously.
6. Opening one repeater item must not close another item automatically.
7. Apply the same pattern to every field group that can reasonably be interpreted as a repeater, even if the collection has only a few items in its initial data.

## Repeater Types

Every repeater must explicitly follow one of these two behaviors. Do not mix them or infer administrative controls solely from the current number of items.

### Fixed Repeaters

A fixed repeater has an exact item count determined by the public layout. For example, a counter row designed for exactly four counters remains a four-item repeater.

Requirements:

1. Render one nested accordion for each fixed item.
2. Do not provide add or delete controls.
3. Preserve the exact number of items required by the layout.
4. Empty content does not remove a fixed item; the item remains editable within its assigned position.

### Administrable Repeaters

An administrable repeater allows the client to decide how many items the section contains.

Each item is represented by a nested accordion whose header contains, in one row:

- the accordion chevron
- the item label
- a red trash-can icon button aligned to the right

The delete control must remain visible while the item accordion is collapsed. It must be independent from the accordion trigger, so activating it never opens or closes the item.

#### Single-Field Items

When every item of an administrable repeater consists of exactly one editable field, such as the options of a select or radio field, do not wrap each item in an accordion. Render each item as one flat row:

- the field itself, inline, taking the available width
- move up and move down controls
- the red trash-can icon button, aligned to the right

Add, reorder and delete behave exactly like accordion items, including the confirmation modal. As soon as an item gains a second field, switch back to the nested accordion layout.

##### Typed Single-Value Items

A narrow exception: when an item is one value plus a selector that only qualifies that value (a social network and its URL, a contact channel and its number), the pair still counts as one field and renders as a flat row: selector on the left, the value taking the remaining width, then the move and delete controls. Both parts must fit in one row on desktop; if the item needs any further input, use the accordion. Reference implementation: `resources/views/admin/settings/_social-link-item.blade.php`.

#### Deleting An Item

Deletion is destructive and must never happen immediately.

Requirements:

1. Activating the trash button opens a confirmation modal.
2. The modal must clearly identify that one repeater item will be removed.
3. Only explicit confirmation removes the item from the current form state.
4. Cancelling leaves the item and its fields unchanged.
5. The removal is persisted through the Live Editor's normal save flow.

Native browser confirmation dialogs are not a substitute when the application has a shared modal pattern.

#### Adding An Item

Place a primary green add button after the final item in the repeater list. The button must include a plus icon and an explicit label such as `Add item`.

Activating the button must:

1. append a new nested accordion to the list
2. initialize all of its editable fields with empty values or documented field defaults
3. assign a clear fallback label until the client enters a meaningful identifying value
4. open the new accordion so it is ready to edit
5. include the new item in the Live Editor's dirty-state and normal save flow

The add button remains at the end of the list after each insertion.

#### Reordering Administrable Items

When item order affects the public result, an administrable repeater must provide an understandable ordering control.

Requirements:

1. Use accessible move-up and move-down controls or an accessible drag handle with a keyboard alternative.
2. Keep ordering controls available from the item header when practical.
3. Disable or hide impossible moves for the first and last items.
4. Recalculate serialized field indexes after every move without losing field values or media selections.
5. Update numbered fallback labels after reordering.
6. Mark the form as modified and refresh the preview after the order changes.

Do not expose ordering controls when the layout defines fixed semantic positions that are not interchangeable.

## Sections Backed By Admin Collections

Some page sections render records owned by a separate admin collection. For example, a contact page may display business locations whose records are managed in the Locations area of the admin panel.

The page's Live Editor must not duplicate those collection records as editable section fields. The section accordion still belongs to the page and still exposes its normal page-level visibility switch, but its body acts as a clear handoff to the collection manager.

Requirements:

1. Identify the source collection by its user-facing name.
2. Explain that the section displays records from that collection and that the records are edited elsewhere in the admin panel.
3. Provide a prominent green navigation button with a right-arrow icon.
4. Label the button with the concrete action and collection name, such as `Manage locations`; avoid vague labels such as `Go to admin` or `Click here`.
5. Link directly to the relevant collection management screen, not to the admin dashboard or an unrelated intermediate page.
6. Keep the explanatory copy concise and understandable to a nontechnical client.
7. Do not render misleading editable controls for fields whose source of truth is the collection.

Recommended message pattern:

```text
This section displays items from the Locations collection.
To edit, add, or remove locations, manage that collection in the admin panel.

[Manage locations →]
```

The exact wording may change, but it must always answer three questions: what content is shown, where it is managed, and how to reach it.

### Selected Records Must Remain The Source Of Truth

A page section may select one record from an admin collection—for example, a featured recipe, post, product, job, or location. The page stores only its selection and truly page-specific presentation content. It must not copy editable attributes that already belong to the selected record.

Requirements:

1. Store a stable reference to the selected record's translation family or logical identity, not a locale-specific slug or URL.
2. Resolve the selected record in the active public locale, using the project's explicit missing-translation fallback.
3. Render collection-owned attributes such as title, description, time, difficulty, product label, featured image, price, or status directly from the selected record whenever those attributes already exist there.
4. Do not duplicate collection-owned attributes as page fields merely to make the section render; extend the collection's content model when a genuinely reusable attribute is missing.
5. Keep only page-placement content in the page section, such as an optional section kicker, button label, visibility, or placement-specific behavior.
6. Provide the normal direct navigation control to manage the source collection.
7. When the selected record changes, its current collection data must appear automatically in preview and on the public page without re-entering it in the page editor.
8. Updating the selected record later must update every placement that reuses it.
9. Do not make the client edit the same fact once in the collection and again in every page placement.

## Multilanguage Editorial Warnings

The base locale is the editorial source of truth. Secondary locales are translations of that source, so outdated-content detection must always flow in one direction:

```text
Base locale change → secondary locales may become outdated
Secondary locale change → base locale never becomes outdated
```

Requirements:

1. Editing a translatable field in the base locale may mark the corresponding field in every secondary locale as outdated.
2. Editing a secondary locale must never create warnings in the base locale.
3. Editing one secondary locale must not make another secondary locale outdated; each translation is compared independently with the base locale.
4. The base locale must never display translation-outdated warnings about itself.
5. Only fields declared translatable by the content schema participate in this flow.
6. A secondary-locale warning remains pending until the client either updates that translated field or explicitly confirms that its existing value still requires no change.
7. Saving a reviewed secondary locale records its confirmation against the current base content and clears the resolved warning state.
8. The language switcher must show the editorial state of every active locale, including missing, draft, outdated, needs review, and published states.

This directionality is mandatory. Bidirectional warning propagation creates an endless review loop and is prohibited.

### Warning Visibility Through Closed Accordions

A pending warning must remain discoverable even when the affected field is hidden inside tabs, section accordions, nested repeater accordions, or deeper supported groups.

The warning state must propagate from the field through every collapsed ancestor:

```text
Editor tab warning
└── Page section accordion warning
    └── Repeater item accordion warning
        └── Outdated field warning and review action
```

Requirements:

1. Mark the affected field with a clearly visible amber warning state and explanatory text.
2. Show an amber warning icon or badge in the header of every ancestor accordion containing one or more pending fields.
3. Show a pending count when an ancestor contains multiple warnings; a single warning may use only the warning icon.
4. Propagate the aggregate warning to the relevant editor tab or top-level navigation control.
5. Keep every warning badge visible while its accordion is collapsed.
6. Update ancestor badges and counts immediately when a field is edited or explicitly acknowledged.
7. Remove a badge only when no pending warnings remain anywhere inside that ancestor.
8. When saving is blocked by an unresolved warning, activate the correct tab, expand the full accordion path, scroll to the first pending field, and focus it.
9. Warning indicators must not toggle visibility switches or perform destructive actions.

For repeaters, warning paths must remain associated with the correct item. The item header should use its meaningful summary label plus the warning badge so the client can identify the affected record without opening every item.

## Dynamic Labels And New-Item Focus

Accordion headers should help clients recognize content without opening it.

Requirements:

1. Update a repeater item's header label as the client edits its primary identifying field.
2. Keep a stable numbered fallback when that field is empty.
3. After adding an item, open its accordion, scroll it into view when needed, and focus its first meaningful field.
4. Preserve the item's warning and error badges when its label changes.

## Live Feedback And Unsaved Changes

The editor must make its current state clear without interrupting the client's flow.

Requirements:

1. Track whether the form differs from its last successfully saved state.
2. Keep the primary save action visible without requiring the client to scroll to the end of a long form.
3. Disable save actions when there are no changes or while a save is in progress.
4. Clearly distinguish `Save draft`, `Publish`, and `Save changes` when those actions are available.
5. Warn before leaving the editor when unsaved changes exist.
6. Refresh the live preview with a short debounce rather than on every keystroke immediately.
7. Show a concise success or error toast after a save attempt.
8. Never mark the form clean until the server confirms a successful save.

## Validation Error Discovery

Validation errors must remain discoverable through the same hierarchy used for multilanguage warnings.

Requirements:

1. Use a visually distinct error treatment; do not make validation errors indistinguishable from amber translation warnings.
2. Propagate error indicators through every containing repeater accordion, section accordion, and editor tab.
3. Keep error indicators visible while ancestors are collapsed.
4. After a failed save, activate the relevant tab, expand the full ancestor path, scroll to the first invalid field, and focus it.
5. Update ancestor error counts as errors are corrected.

## Accessibility

All Live Editor behavior must be operable without relying only on a mouse or on color perception.

Requirements:

1. Use real buttons for accordion triggers and actions; do not use a clickable `div` as the only control.
2. Keep `aria-expanded` and `aria-controls` synchronized on accordion triggers.
3. Give icon-only buttons accessible names and visible tooltips where the icon may be ambiguous.
4. Provide visible keyboard focus states and logical tab order.
5. Give controls adequate touch targets for mobile use.
6. Pair color-coded states with icons, text, or counts.
7. Ensure modal confirmation traps focus appropriately, can be cancelled, and returns focus to the originating control.

## Prohibited Patterns

Do not implement:

- several page sections inside one accordion
- section fields displayed loose in the editor panel
- all repeater item fields expanded in one long form
- two, three, or four columns of independent inputs
- a visibility switch that also toggles the accordion
- add or delete controls on a fixed repeater
- immediate deletion without confirmation
- a delete button hidden inside the expanded accordion body
- an icon-only add button with no visible action label
- duplicating collection-owned fields inside a page section
- linking collection-backed sections only to the generic admin dashboard
- vague collection navigation labels that do not name the destination
- copying a selected collection record's title, description, metadata, or media into duplicate page fields
- storing a selected record by localized slug or URL when a stable translation-family reference exists
- translation warnings propagated from a secondary locale back to the base locale
- pending field warnings visible only after manually opening every ancestor accordion
- a section-level warning that does not identify the affected nested repeater item
- raw media IDs, JSON, markup tokens, or internal storage paths exposed as normal client fields
- one editorial title split into multiple fields to control typography, emphasis, or line wrapping
- asking the client to type highlight tokens, `<span>` elements, or other markup manually
- rendering arbitrary HTML from an editorial title instead of an allowlisted emphasis token
- using a plain textarea for ordinary running text without a documented reason
- exposing an unrestricted TinyMCE toolbar or formatting that the public component cannot render correctly
- enabling TinyMCE image, media, table, font, color, or source-code features without explicit component support
- trusting or rendering WYSIWYG HTML without matching server-side sanitization
- loading an unversioned rich-text editor script or requiring a remote editor API key unnecessarily
- presenting a bare URL field as the default control for an editable button destination
- mixing internal page IDs, route names, or slugs into the client-facing destination options
- showing the custom destination input while an internal page is selected
- accepting unsafe or unsupported URI schemes from a custom destination
- storing an internal destination as a locale-specific literal URL that must be edited again in every translation
- omitting the `Open in a new tab` option from an editable button destination
- creating, deleting, reordering, or changing public form fields from the Live Editor
- editing form validation rules, submission behavior, security controls, or backend mappings in the Live Editor
- assuming a form should be administrable without explicit project approval
- sending clients to a generic admin dashboard when a direct form-submission listing exists
- side-by-side edit and preview panels compressed into a mobile viewport
- a desktop editor panel narrower than the standard minimum or wider than half of the workspace
- a draggable resize divider with no keyboard alternative
- save controls available only at the bottom of a long scrolling form
- silent loss of unsaved changes during navigation
- a Back action hard-coded to the admin listing when the editor was opened from a public page
- losing the editor's return location after saving, reloading, publishing, or switching locale
- accepting an unvalidated external return destination
- clickable links inside the embedded preview that navigate the iframe, open another editor, or leave the editing context
- validation errors hidden inside collapsed tabs or accordions
- mouse-only accordion, ordering, or modal interactions
- ordering controls for fixed semantic positions
- nested accordions for arbitrary field groups that are not repeaters or identifiable subsections

## Acceptance Checklist

A Live Editor form is visually compliant only when all of the following are true:

- independent fields use a single-column layout
- desktop and mobile editor shells preserve usable, separate edit and preview modes
- the desktop editor panel resizes from the standard narrow width up to half of the workspace using an accessible divider
- Back returns to the validated entry context and preserves that context throughout the editing flow
- links inside the embedded preview cannot navigate; real destinations remain testable through the separate public-page action
- labels, help text, and specialized controls hide implementation details from the client
- editorial titles remain one field and expose supported inline emphasis through a visual selection action
- highlight markup is serialized safely, rendered through an allowlist, and never shown as raw syntax to the client
- responsive title line wrapping remains owned by the public template rather than separate content fields
- running-text fields use TinyMCE by default, with a minimal reusable preset tailored to their public component
- every rich-text value is synchronized with the Live Editor lifecycle and sanitized against the same server-side allowlist it renders
- TinyMCE never exposes images or other unsupported document-building controls merely because the library provides them
- every editable button destination uses a page selector first, with `Custom` last and a conditional manual field
- internal page choices are stored once as logical references and resolve automatically to the current public locale
- saved button destinations load back into the correct internal-page or custom state, missing translations follow an explicit fallback, and custom values are validated server-side
- every button destination exposes and safely persists an `Open in a new tab` checkbox
- only client-controlled content and supported options are editable
- public form structure and behavior remain code-owned, with only explicitly allowed labels, errors, and enumerated options exposed
- forms that store submissions link directly to their authorized submission listing
- every identifiable page section has exactly one top-level accordion
- every top-level section accordion has an independent visibility switch
- no top-level accordion combines unrelated page sections
- every repeater item is contained in its own nested accordion
- multiple repeater item accordions can remain open simultaneously
- every repeater is explicitly fixed or administrable
- fixed repeaters preserve their layout-defined item count and expose no add or delete controls
- administrable repeaters expose a red delete icon in every item header and require confirmation
- administrable repeaters end with a green, labeled add button that creates an empty editable item
- order-dependent administrable repeaters provide accessible reordering and preserve their data after moves
- repeater headers update from meaningful item content and new items open with focus in the correct field
- collection-backed sections explain their source and link directly to a clearly named collection manager
- collection-backed records are not duplicated as editable page fields
- featured collection records remain the source of truth and resolve their current localized content automatically
- translation warnings flow only from the base locale to secondary locales
- every pending translation warning is visible at field, nested item, section, and tab levels
- resolving a warning updates all ancestor indicators immediately
- a blocked save reveals and focuses the first unresolved translated field
- validation errors propagate through every ancestor and reveal the first invalid field
- save, preview, dirty-state, and leave-warning feedback remain consistent throughout the editor
- accordion, modal, and repeater actions are keyboard accessible and expose correct assistive semantics
- the collapsed headers let the client identify the desired section and item without opening unrelated content
- the form remains usable at the narrowest supported mobile width
