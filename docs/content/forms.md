# Public Forms

> The contract for every public form: fields are code, the client edits labels. One table per form, one admin
> tab per form, the same anti-spam layer on all of them. The contact form is the reference implementation.

## Contents

- Principles
- Reference Implementation: The Contact Form
- Anti-Spam Layer
- Metadata Stored With Every Submission
- Live Editor Section Contract
- Admin: Submissions
- Notifications
- Adding A New Form: Checklist
- Anti-Patterns

Read with the "Forms Are Code-Owned" rules in [../admin/live-editor-ui-ux.md](../admin/live-editor-ui-ux.md).

## Principles

1. **Forms are code.** Fields, types, order, validation, conditional behaviour, storage and notifications are written in the repository and deployed with it. The Live Editor never adds, removes or reorders fields.
2. **The editable surface is small and explicit.** Only client-facing labels, the button text, the success message, the options of declared `select` / radio / checkbox-group fields, and the notification settings are editable, and only because the form contract lists them.
3. **One table per form.** Submissions are plain rows in a dedicated table with the form's real columns plus a fixed metadata block. No generic JSON "form builder" table.
4. **One tab per form** under the admin "Submissions" menu, driven by `config('cms.forms')`.
5. **Every form carries the same anti-spam layer**: CSRF, honeypot, minimum fill time, per-IP rate limit and Google reCAPTCHA v3 when the security settings have keys.
6. **Submissions are records, not content.** They are not tracked by the content revision ledger and clients never edit them. They do travel inside snapshots ([snapshots.md](../operations/snapshots.md)).

## Reference Implementation: The Contact Form

| Concern | Where |
|---------|-------|
| Page template | `resources/views/templates/contact.blade.php`, registered as `contact` in `config/cms.php` (`templates`) with sections `intro` and `form` |
| Starter page | `database/seeders/ContactPageSeeder.php` (slug `contacto`), run by `cms:install` and `DatabaseSeeder` |
| Public route | `POST /forms/contact` → `forms.contact.store`, plus `POST /{locale}/forms/contact` → `localized.forms.contact.store`, both in `routes/web.php` with `throttle:forms` |
| Request | `app/Http/Requests/Public/ContactSubmissionRequest.php` extending `PublicFormRequest` |
| Controller | `app/Http/Controllers/Public/ContactFormController.php` |
| Table / model | `contact_submissions`, `app/Models/ContactSubmission.php` |
| Notification | `app/Services/Forms/SubmissionNotifier.php` + `app/Mail/ContactSubmissionReceived.php` + `resources/views/emails/contact-submission.blade.php` |
| Admin | `app/Http/Controllers/Admin/SubmissionController.php` (shared) + `resources/views/admin/submissions/contact/{_columns,_row,_detail}.blade.php` |
| Live Editor section | `resources/views/admin/pages/sections/contact_form.blade.php` + `_option-repeater-item.blade.php` |
| Registry entry | `config('cms.forms.contact')` |
| Tests | `tests/Feature/ContactFormTest.php`, `tests/Feature/AdminSubmissionsTest.php` |

### Submission flow

```
POST /forms/{form}
  → throttle:forms (10/min per IP)
  → {Form}SubmissionRequest: field rules + page_id + reCAPTCHA token rule
  → honeypot tripped? → redirect with success flag, store nothing
  → store row (fields + metadata)
  → SubmissionNotifier: reserve durable delivery + queue email if the page's form section enables it and mail is on
  → redirect to the page URL + #anchor with a session flag the template turns into the success message
```

Validation errors redirect back with `$errors`; the template lists them above the form and re-fills inputs with `old()`. Error messages are translated in `lang/*/public.php` under `forms.errors`.

## Anti-Spam Layer

Every public form includes all of these. None is optional.

| Control | Implementation | Notes |
|---------|----------------|-------|
| CSRF | `@csrf` in the form | Standard `web` middleware |
| Honeypot | `<x-forms.honeypot />` renders a visually hidden `website` text field and an encrypted `_form_token` bound to the page id, form key, locale and server render time | `PublicFormRequest::isHoneypotTripped()` is true when `website` is filled or a valid token was submitted less than 3 seconds after render. Missing, malformed, future, expired (over two hours), or wrong-context tokens return a translated reload message. A client-supplied `_ts` never participates in validation. Valid tokens are preserved when field validation redirects back, and a translated short submit wait prevents humans retrying a newly rendered token from being silently dropped. Controllers **redirect as if it succeeded** and store nothing: never tell a bot it was caught. |
| Rate limit | `->middleware('throttle:forms')` on the route | Limiter `forms` is defined in `AppServiceProvider` (10 per minute per IP). |
| reCAPTCHA v3 | `<x-forms.recaptcha action="{form}" />` + `App\Rules\RecaptchaToken` added automatically by `PublicFormRequest::rules()` | Driven by the `security` settings group. With no keys the component renders nothing and the rule passes, so local and test environments need no credentials. The rule is implicit: a missing token fails when reCAPTCHA is on. Network errors fail closed with a translated retry message. A successful response must contain literal boolean success, the expected action, a numeric score between 0 and 1 meeting the threshold, and a hostname matching the configured `APP_URL` host (never the submitted Host header). Incomplete responses fail closed. The verified score is stored in `recaptcha_score`. |

### Security settings

Group `security` in Settings (`admin/settings?tab=security`):

| Key | Type | Meaning |
|-----|------|---------|
| `recaptcha_site_key` | string | Public site key, injected into every public form |
| `recaptcha_secret_key` | password | Server-side secret |
| `recaptcha_min_score` | string | 0–1, default `0.5`; lower scores are rejected |

Rows are seeded by `SettingsSeeder` and by the migration `2026_09_14_000002_add_security_settings.php` for existing installs. reCAPTCHA is active only when both keys are present.

## Metadata Stored With Every Submission

`PublicFormRequest::submissionMeta()` returns the block every submissions table must have columns for:

| Column | Source |
|--------|--------|
| `page_id` | Required hidden input; must resolve to a published, non-future page in the route locale, with the form’s registered template and a present, visible registered section. Used for redirects and notifications; token binding prevents swapping page context. |
| `locale` | `app()->getLocale()` |
| `ip` | `Request::ip()` |
| `user_agent` | Truncated to 500 chars |
| `referer` | Truncated to 500 chars |
| `recaptcha_score` | Score returned by Google, or `null` when disabled |
| `read_at` | Set the first time an admin opens the detail view |
| `notified_at` | Set when the notification email was sent |
| `created_at` / `updated_at` | Timestamps |

## Live Editor Section Contract

The form's page section (`sections.form` in the contact template) exposes exactly:

- `heading` (translatable)
- `labels.<field>` for every visible field (translatable)
- `button_label`, `success_message` (translatable)
- `<field>_options.*.label` for each administrable select/radio field, edited with the generic repeater (translatable)
- `notify_enabled` (boolean) and `notify_email` (string), both `preserve`: configured once for every locale
- a note explaining that the fields are code-owned
- a warning when mail is disabled globally
- a **green button with a right-arrow icon** linking to `route('admin.submissions.index', ['form' => '<key>'])`, shown to users allowed to view submissions

Declare every path in the template `schema` with the right flags so translations and the stale-field detection keep working ([multilanguage.md](../concepts/multilanguage.md)). Empty labels fall back to `lang/*/public.php` (`forms.<form>.<field>`), so a fresh translation renders correctly before the client edits anything.

Option values: the starter stores the option **label** as the submitted value (`subject` is free text in the table). If a form needs stable machine values, add a code-owned `value` per option in the item partial and keep it out of the editable surface, as [live-editor-ui-ux.md](../admin/live-editor-ui-ux.md) requires.

### Generic repeater

`resources/js/admin.js` ships a generic repeater for administrable option lists. Markup contract:

```html
<div data-repeater data-repeater-key="subject_options"
     data-item-label="{{ __('admin.option_item_fallback_label') }}"
     data-confirm-remove="{{ __('admin.confirm_remove_option') }}"
     data-confirm-label="{{ __('admin.remove') }}">
  <div data-repeater-list> ...items... </div>
  <p data-repeater-empty>…</p>
  <button type="button" data-repeater-add>…</button>
  <template data-repeater-template> ...one item with index __INDEX__... </template>
</div>
```

Each item is `[data-repeater-item]` with `[data-repeater-label-input]`, `[data-repeater-move-up]`, `[data-repeater-move-down]` and `[data-repeater-remove]`. Single-field items (option lists) render as flat rows, per the "Single-Field Items" rule in [live-editor-ui-ux.md](../admin/live-editor-ui-ux.md); items with several fields add the accordion hooks `[data-repeater-toggle]`, `[data-repeater-body]`, `[data-repeater-icon]` and `[data-repeater-item-label]`. Input names must contain `[<key>][<index>]`; the script re-indexes them on add, move and remove, and fires a `change` event so the editor's dirty state and Save button react. Reuse `_option-repeater-item.blade.php` for option lists by passing `sectionKey`, `field`, `index`, `option`.

## Admin: Submissions

- Sidebar item **Submissions** (admins and editors) with a badge of unread submissions across forms, linking to the first registered form.
- `SubmissionController@index` renders one tab per key in `config('cms.forms')`, with the unread count per tab, status filters (all / new / read), text search over the form's `searchable` columns, and a date range.
- `@show` marks the row read (`markRead()`) and renders the form's `_detail` partial next to the shared metadata panel.
- `@destroy` deletes with the shared confirmation modal.
- Per-form partials live in `resources/views/admin/submissions/{form}/`: `_columns` (extra `<th>`), `_row` (matching `<td>`, receives `$submission` and `$url`), `_detail` (field list).
- Tab labels come from `admin.forms.<key>` in `lang/*/admin.php`.

## Notification Delivery And Inbox Work

`SubmissionNotifier::notify($submission, $page, $sectionKey = 'form')` reads the section's enabled flag and valid recipient and requires `mail_enabled`. It atomically reserves a delivery generation only from `skipped` or `failed`, with a new opaque token, recipient and zeroed attempts. Already queued, sending, retrying or sent deliveries cannot be reserved again. Existing `notified_at` rows are marked sent by the workflow migration and never re-enqueued.

`SendSubmissionNotification` runs asynchronously on the configured queue connection; if the application chooses `sync`, delivery uses the `database` connection instead. Run a worker for that connection (`php artisan queue:work database` for the fallback). Queue insertion happens after commit. Queue errors leave a durable `failed` state rather than failing the visitor's successful submission.

The job uses four attempts and backoff of 30, 120 and 600 seconds. It claims the generation with a compare-and-swap before sending; duplicate and obsolete jobs cannot send or overwrite a newer generation. A worker killed during delivery can reclaim an expired sending lease after its 60 second timeout. Configure the queue's retry interval above the worker timeout. Successful delivery stamps `notified_at`; retryable transport exceptions preserve a diagnostic and `retrying` state, and exhausted jobs record `failed` with a failure timestamp. Email transport cannot guarantee exactly-once delivery if a worker dies after the provider accepted mail but before the success was persisted.

Brevo selection remains unchanged: a nonblank database `brevo_api_key` takes precedence over `mail.mailers.brevo.key` / `BREVO_API_KEY`. Blank keys use the configured default mailer. Sender settings and visitor reply-to remain owned by the existing Mailable.

The submissions detail screen displays translated delivery state, attempts, actionable failure guidance and a retry button for failed or skipped deliveries. Admins and editors may retry; the atomic generation reservation also protects concurrent clicks. Disabled mail or missing notification settings returns an actionable translated message.

Submissions retain `read_at` for unread badges and the legacy new/read filters. A separate `workflow_status` (`new`, `in_progress`, `resolved`) records work independently from opening the detail screen. Admins and editors can select a responsible admin/editor, change state, and append internal notes. Notes are attributed to the logged-in user, length-validated and escaped in the detail view; they never appear on the public site or outgoing notification. The inbox filters by workflow, assignee (including unassigned), delivery state, text and date. Workflow controls are distinct from the immutable visitor fields. Deleting a submission cascades its notes; deleting a user clears assignment and preserves the note with a deleted-user attribution.

Tests use `Queue::fake()` to verify reservation and async dispatch, and invoke jobs with `Mail::fake()` or a transport double to verify success, duplicate suppression, retries and exhausted delivery. RefreshDatabase tests use isolated SQLite databases.

## Adding A New Form: Checklist

Follow this list in order. Each step names the contact counterpart to copy.

1. **Contract.** Write the field list (name, type, required, rules), which labels/options are administrable, where submissions go, and whether email notifications apply. If any part is unclear, stop and ask the project owner; do not guess.
2. **Migration + model.** `create_{form}_submissions_table` with the real field columns plus the metadata block above. Include the shared workflow/assignment and notification-generation metadata and a form-owned notes relation, as in the contact workflow migration. Model with `casts` for the timestamps and `recaptcha_score`, `scopeUnread`, `isRead()`, `markRead()`. Do **not** add `RecordsContentRevisions`.
3. **Request.** `app/Http/Requests/Public/{Form}SubmissionRequest.php` extending `PublicFormRequest`: `formKey()` returns the registry key (also the reCAPTCHA action), `fieldRules()` the field rules, `messages()` translated via `public.forms.errors.*`.
4. **Controller.** `app/Http/Controllers/Public/{Form}FormController.php` following the contact flow: honeypot → store → notify → redirect with a success flag and anchor.
5. **Routes.** `POST /forms/{form}` inside the `public.locale` group and its `localized.` twin under the `{locale}` prefix, both with `throttle:forms`, before the page catch-all.
6. **Template.** Section markup with `@csrf`, hidden `page_id`, labels from the section with `public.*` fallbacks, `<x-forms.honeypot :page="$page" form="{form}" />`, `<x-forms.recaptcha action="{form}" />`, success and error blocks, `old()` values, action from `LocalizedRoute::to('forms.{form}.store')`.
7. **Schema.** Register the template/section in `config/cms.php` with every editable path flagged `translatable` or `preserve`.
8. **Live Editor section partial.** `resources/views/admin/pages/sections/{template}_{section}.blade.php` with the contract above: note, labels, options repeater, notifications, green "View submissions" button.
9. **Registry.** Add `config('cms.forms.{form}')` with `model`, `searchable`, `recaptcha_action`, `template` and `section` (the server-verified page binding).
10. **Admin partials.** `resources/views/admin/submissions/{form}/_columns|_row|_detail.blade.php`.
11. **Notification.** If email applies, a Mailable + view; either reuse `SubmissionNotifier` (generalise the model type) or add a sibling with the same rules.
12. **Translations.** `admin.forms.{form}`, field labels `admin.submission_field_*` you introduce, and `public.forms.{form}.*` fallbacks in every locale under `lang/`. Keep `lang/es/admin.php` and `lang/en/admin.php` key-for-key identical.
13. **Seeder** for the starter page if the form ships with the project, and a `MenuSeeder` link when it belongs in navigation.
14. **Tests.** Copy `ContactFormTest` and `AdminSubmissionsTest`: render, valid submit, validation, honeypot, reCAPTCHA on/off, notification on/off, rate limit, admin list/filters/detail/delete.
15. **Docs.** Add the form to this document's reference table if it ships with the starter; project forms document their contract in the project's own docs.

## Anti-Patterns

- a generic "forms" table with a JSON payload instead of one table per form
- exposing field structure, validation or destinations in the Live Editor
- a public POST route without `throttle:forms`, the honeypot component or the reCAPTCHA component
- revealing to the visitor that the honeypot caught them
- storing submissions through the content revision ledger
- linking the editor section to the dashboard instead of the form's own submission listing
- hardcoding label text in the template without a `public.*` fallback
