# Create Layout Field Skill

## How To Use

This file is not meant to be copied into the prompt.

It is meant to be referenced directly, for example:

```md
[create-layout-field.md](docs/skills/create-layout-field.md)

The header needs a second, white logo for the transparent variant used on the home hero,
and the footer must show the opening hours under the contact block.
```

When this file is referenced, the agent should treat the rest of the user's message as the header/footer change to implement.

## Instruction For The Agent

If this file was referenced in the prompt:

1. Use these canonical documents as the source of truth:
   - [layout-header-footer.md](../layout-header-footer.md): ownership model, admin screens, shortcut pattern, `BusinessContact`, extension checklists
   - [settings.md](../settings.md): settings model, types and groups
   - [menu-guide.md](../menu-guide.md): when the change is about navigation
   - [live-editor-ui-ux.md](../live-editor-ui-ux.md): repeater and admin UX rules
   - [admin-translations-guide.md](../admin-translations-guide.md): labels and help texts
2. Treat all text after the reference to this file as the functional requirement.
3. For every new visible element, decide first **who owns the fact**:
   - already stored elsewhere (logo, menus, contact data, a collection) → add a shortcut card, never a copy
   - a new fact specific to the region → a setting in group `header` or `footer`
   - a new business-wide fact (e.g. opening hours) → a setting in group `contact`, read through `BusinessContact`
4. Follow the matching checklist in `layout-header-footer.md` ("Extending"): seeder + migration, validation rule in `PersistsSettings`, translations in `es` and `en`, controller ordering, composer variable, partial rendering, test.
5. Empty values must hide the element in the public layout. Never make a contact field required.
6. Keep the markup in the partials; the client edits data only.
7. Update the ownership map in `layout-header-footer.md` and add a changelog entry.

## What The User Should Describe After Referencing This File

- what the visitor should see and where (header, footer, both)
- whether the value is per site, per locale, or per page
- whether the data already exists somewhere in the admin
- any constraints (only some networks, formats, links)
