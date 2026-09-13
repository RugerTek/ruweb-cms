# Create Form Skill

## How To Use

This file is not meant to be copied into the prompt.

It is meant to be referenced directly, for example:

```md
[create-form.md](docs/skills/create-form.md)

Quote request form on the "Servicios" page

Fields:
- name (required)
- company (optional)
- email (required)
- service (select, administrable options: Web, Branding, SEO)
- budget (radio, code-owned options: <5k, 5-15k, >15k)
- message (required)

Email notifications: yes
```

When this file is referenced, the agent should treat the rest of the user's message as the form specification to implement.

## Instruction For The Agent

If this file was referenced in the prompt:

1. Use these canonical documents as the source of truth:
   - [forms.md](../forms.md): the form contract, anti-spam layer, storage, admin and the step-by-step checklist
   - [live-editor-ui-ux.md](../live-editor-ui-ux.md): "Forms Are Code-Owned" and the administrable repeater requirements
   - [templates.md](../templates.md) and [pages-guide.md](../pages-guide.md): where the form's page section lives
   - [multilanguage.md](../multilanguage.md): translatable vs preserved paths in the schema
   - [settings.md](../settings.md): the `security` group with the reCAPTCHA keys
   - [admin-translations-guide.md](../admin-translations-guide.md): translation conventions
2. Treat all text after the reference to this file as the functional requirement.
3. Start from the contact form as the reference implementation and follow the "Adding A New Form" checklist in `forms.md` in order. Do not skip the anti-spam controls, the registry entry, the admin partials, the translations or the tests.
4. Expose in the Live Editor only what the specification declares administrable: labels, button text, success message, options of declared select/radio/checkbox-group fields, and notification settings. Everything else is code.
5. If the specification does not say whether a field's options are administrable, ask before exposing them. Do not build a form builder as a fallback.
6. Create one dedicated submissions table with the real field columns plus the shared metadata block. Never store submissions as generic JSON.
7. Register the form in `config('cms.forms')` so it gets its own tab under Submissions, and add the green "View submissions" button to the editor section.
8. Keep `lang/es/admin.php` and `lang/en/admin.php` key-for-key identical and add `public.forms.<form>.*` fallbacks in every public locale.
9. Write feature tests mirroring `tests/Feature/ContactFormTest.php` and `tests/Feature/AdminSubmissionsTest.php`.
10. If the request is ambiguous, make the smallest reasonable interpretation consistent with the existing architecture and state the assumption.

## What The User Should Describe After Referencing This File

- form name and the page or template that hosts it
- fields: name, type, required or optional, validation rules
- which labels and which option lists are administrable from the Live Editor
- whether email notifications apply
- any extra metadata or behaviour beyond the shared contract
