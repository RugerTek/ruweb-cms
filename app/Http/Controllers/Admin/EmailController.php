<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Mail\MailSettings;
use App\Services\Settings\SettingOperations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

/**
 * Admin > Email (docs/operations/email.md): the provider (Brevo by API key, or any SMTP server), the sender, the
 * «Activar envío de emails» switch and «Enviar correo de prueba», which sends with the saved settings and shows the
 * provider's real error when it fails. Secrets (the Brevo key, the SMTP password) are write-only: the form never
 * shows them and an empty field keeps the stored value.
 */
class EmailController extends Controller
{
    /** Settings this screen writes; anything else in the request is ignored. */
    public const KEYS = ['mail_enabled', 'mail_provider', 'brevo_api_key', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'mail_from_address', 'mail_from_name'];

    public function __construct(private readonly MailSettings $mail) {}

    public function index(): View
    {
        Setting::clearCache();

        return view('admin.email.index', [
            'values' => collect(self::KEYS)->mapWithKeys(fn (string $key) => [$key => in_array($key, ['brevo_api_key', 'smtp_password'], true) ? null : Setting::get($key)])->all(),
            'stored' => [
                'brevo_api_key' => trim((string) Setting::get('brevo_api_key')) !== '',
                'smtp_password' => (string) Setting::get('smtp_password') !== '',
            ],
            'status' => $this->mail->summary(),
            'providers' => MailSettings::PROVIDERS,
            'encryptions' => MailSettings::ENCRYPTIONS,
        ]);
    }

    public function update(Request $request, SettingOperations $settings): RedirectResponse
    {
        $input = array_intersect_key((array) $request->input('settings', []), array_flip(self::KEYS));
        $provider = $input['mail_provider'] ?? $this->mail->provider();
        $enabling = filter_var($input['mail_enabled'] ?? $this->mail->enabled(), FILTER_VALIDATE_BOOLEAN);
        $needsSmtp = $enabling && $provider === MailSettings::PROVIDER_SMTP;

        validator(['settings' => $input], [
            'settings.mail_enabled' => ['sometimes', 'nullable', Rule::in(['0', '1', 0, 1, true, false, 'on', 'off'])],
            'settings.mail_provider' => ['sometimes', Rule::in(MailSettings::PROVIDERS)],
            'settings.brevo_api_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.smtp_host' => [Rule::requiredIf($needsSmtp && (array_key_exists('smtp_host', $input) || $this->mail->smtpHost() === '')), 'nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-\[\]:]+$/'],
            'settings.smtp_port' => [Rule::requiredIf($needsSmtp && (array_key_exists('smtp_port', $input) || $this->mail->smtpPort() === 0)), 'nullable', 'integer', 'min:1', 'max:65535'],
            'settings.smtp_encryption' => ['sometimes', Rule::in(MailSettings::ENCRYPTIONS)],
            'settings.smtp_username' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.smtp_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.mail_from_address' => ['sometimes', 'nullable', 'email', 'max:255'],
            'settings.mail_from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], [], [
            'settings.smtp_host' => __('admin.settings_fields.smtp_host.label'),
            'settings.smtp_port' => __('admin.settings_fields.smtp_port.label'),
            'settings.mail_from_address' => __('admin.settings_fields.mail_from_address.label'),
        ])->validate();

        foreach (['smtp_host', 'smtp_username', 'mail_from_address', 'mail_from_name'] as $key) {
            if (array_key_exists($key, $input) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        $settings->persist($request->user(), $input);
        Setting::clearCache();

        return redirect()
            ->route('admin.email.index')
            ->with('success', __('admin.email_saved'));
    }

    public function sendTestEmail(Request $request): RedirectResponse
    {
        $request->validate([
            'test_email' => ['required', 'email'],
        ]);

        Setting::clearCache();
        $to = (string) $request->input('test_email');
        if (! $this->mail->configured()) {
            return redirect()
                ->route('admin.email.index')
                ->with('error', __($this->mail->provider() === MailSettings::PROVIDER_SMTP ? 'admin.email_test_no_smtp' : 'admin.email_test_no_key'));
        }

        $this->mail->apply();
        $fromAddress = Setting::get('mail_from_address') ?: config('mail.from.address');
        $fromName = Setting::get('mail_from_name') ?: config('mail.from.name');

        try {
            Mail::mailer($this->mail->mailer())->raw(
                __('admin.email_test_body', ['app' => config('app.name')]),
                function ($message) use ($to, $fromAddress, $fromName) {
                    $message->to($to)
                        ->from($fromAddress, $fromName)
                        ->subject(__('admin.email_test_subject', ['app' => config('app.name')]));
                }
            );
            $this->mail->clearError();

            return redirect()
                ->route('admin.email.index')
                ->with('success', __('admin.email_test_success', ['email' => $to]));
        } catch (Throwable $e) {
            $this->mail->recordError($e);
            Log::warning('Test email failed.', ['provider' => $this->mail->provider(), 'error' => $this->mail->scrub($e->getMessage())]);

            return redirect()
                ->route('admin.email.index')
                ->with('error', __('admin.email_test_failed', ['error' => $this->mail->scrub($e->getMessage())]));
        }
    }
}
