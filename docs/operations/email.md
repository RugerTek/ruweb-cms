# Outgoing email

Admin > Email decides how the site sends email: **Brevo** with an API key, or **any SMTP server** (the hosting's
mailbox, Gmail/Google Workspace, Microsoft 365, Mailgun…). Every email of the site goes through the same choice:
order emails ([../shop/checkout.md](../shop/checkout.md)), contact form notifications
([../content/forms.md](../content/forms.md)), the Meta reconnect warning
([connectors.md](connectors.md)), the welcome email of a new user and the test email.

**No feature depends on email going out.** With email off, incomplete or failing, the site sells, receives
contact messages and keeps working: orders appear in Admin > Orders, messages in Admin > Submissions, and every
skipped or failed email leaves a line in the log. This is the rule for hosted RuWeb (RugerTek's Brevo, included) and
self-hosted RuWeb (the client's own SMTP) alike; a shop without email is described in
[../shop/checkout.md](../shop/checkout.md#sin-email).

| Piece | Where |
|---|---|
| State and configuration (provider, on/complete, mailer, last error, MCP summary) | `app/Services/Mail/MailSettings.php` |
| Panel screen and «Enviar correo de prueba» | `app/Http/Controllers/Admin/EmailController.php`, `resources/views/admin/email/index.blade.php` |
| Applying the settings on boot, Brevo transport, last-error tracking | `app/Providers/BrevoMailServiceProvider.php` |
| Test email from the console | `php artisan mail:test` (`app/Console/Commands/TestMail.php`) |
| Settings | `app/Support/SettingDefaults.php` (group `email`), `database/migrations/2026_11_26_000001_add_smtp_mail_settings.php` |
| Texts | `lang/es/admin.php`, `lang/en/admin.php` (`settings_fields.*`, `email_*`) |
| Tests | `tests/Feature/MailSmtpSettingsTest.php`, `tests/Feature/CheckoutEndToEndTest.php` (a shop without email) |

## Settings

| Setting | Type | Meaning |
|---|---|---|
| `mail_enabled` | boolean | «Activar envío de emails». Off by default: nothing goes out. |
| `mail_provider` | `brevo` \| `smtp` | Starts as `brevo`, so sites that already sent with a Brevo key keep doing so. |
| `brevo_api_key` | password | Brevo API key. Empty falls back to `BREVO_API_KEY` (`mail.mailers.brevo.key`). |
| `smtp_host`, `smtp_port` | string, integer | Server and port (1–65535, 587 by default). Both required to turn sending on with SMTP. |
| `smtp_encryption` | `tls` \| `ssl` \| `none` | `tls`: STARTTLS required (usually 587). `ssl`: TLS from the start (smtps, usually 465). `none`: plain connection, never upgraded. |
| `smtp_username`, `smtp_password` | string, password | Empty username = the server does not authenticate. |
| `mail_from_address`, `mail_from_name` | string | Sender of every email; empty keeps `MAIL_FROM_*`. |

Secrets (`brevo_api_key`, `smtp_password`) are `password` settings ([../admin/settings.md](../admin/settings.md)):
stored encrypted with `APP_KEY`, never rendered back (the form shows «guardada» and an empty field keeps the stored
value) and never returned by MCP (`settings_get` / `settings_list` mask them).

Saving refuses turning sending on with SMTP while the server or the port is missing; saving SMTP **off** is allowed,
so it can be prepared first. The screen only writes the keys above (`EmailController::KEYS`).

## States

`MailSettings` answers, for every sender:

| Method | True when |
|---|---|
| `enabled()` | The switch is on. Senders check this one: off = skip and log. |
| `configured()` | The chosen provider can connect: the Brevo key (panel or env), or SMTP host and port. |
| `active()` | Both. This is what the panel shows as «activo», what removes the shop warning and what makes the buyer's order page mention the email. |
| `mailer()` | `brevo` or `smtp` when the chosen provider is complete; otherwise the deployment's `MAIL_MAILER`, so a setup done only in `.env` keeps working. |

`apply()` runs on every boot (and again before a test email): it rebuilds Laravel's `smtp` mailer from the panel
when SMTP is chosen and complete (with a 15 s timeout, so a wrong host fails instead of hanging the panel), makes
the chosen mailer the default while sending is active, and sets the sender. Without the `settings` table
(installer, first deploy) it keeps the `.env` values.

## Test email and errors

«Enviar correo de prueba» sends with the **saved** settings through the chosen provider. On failure the panel shows
the provider's real error (connection refused, authentication failed, certificate…) with any secret replaced by
`[oculto]`. The last failure of any email (test, queued order email, contact notification) is kept with its date and
provider and shown on Admin > Email until an email goes out again. `php artisan mail:test` does the same from the
console.

Common SMTP errors:

| Error | Usual cause |
|---|---|
| `Connection could not be established` / `Connection refused` / timeout | Wrong server or port, or the hosting blocks outgoing SMTP (try 587 ↔ 465, or ask the hosting). |
| `535 Authentication failed` | Wrong user or password; Gmail and Microsoft 365 need an app password. |
| Certificate / `STARTTLS` errors | Encryption does not match the port (587 → TLS, 465 → SSL). |
| `Sender address rejected` | The sender (`mail_from_address`) is not a mailbox of that account or domain. |

## MCP

`cms_capabilities` returns `mail`: provider, `enabled`, `configured`, `active`, sender address, whether the Brevo
key and the SMTP user and password are set (never their values), SMTP host, port and encryption, `last_error` and
this guide. The settings themselves are read and written with `settings_get` / `settings_update` like any other
setting, with secrets masked on read.

## Hosted and self-hosted

- **Hosted RuWeb:** RugerTek's Brevo key goes in `BREVO_API_KEY` (or the panel); the client only turns sending on
  and sets the sender.
- **Self-hosted:** the client picks **Servidor SMTP** and enters the data of their hosting or mail provider; no
  `.env` change is needed.

Order emails are queued (`QUEUE_CONNECTION`): with a real queue they need a running worker; with `sync` they are
sent during the request. Either way a failure is caught and logged and never undoes the order.
