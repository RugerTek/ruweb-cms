<?php

namespace Tests\Feature;

use App\Mail\ContactSubmissionReceived;
use App\Models\ContactSubmission;
use App\Models\Locale;
use App\Models\Page;
use App\Models\Setting;
use Database\Seeders\ContactPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    protected Page $page;

    protected function setUp(): void
    {
        parent::setUp();

        Locale::query()->firstOrCreate(['code' => 'es'], ['name' => 'Español', 'is_base' => true, 'is_active' => true, 'is_public' => true, 'sort_order' => 0]);
        $this->seed([\Database\Seeders\SettingsSeeder::class, ContactPageSeeder::class]);
        $this->page = Page::where('slug', 'contacto')->firstOrFail();
        RateLimiter::clear('forms');
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'page_id' => $this->page->id,
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
            'phone' => '+34 600 000 000',
            'subject' => 'Consulta general',
            'message' => 'Hola, quisiera más información sobre sus servicios.',
            'website' => '',
            '_ts' => time() - 10,
        ], $overrides);
    }

    public function test_contact_page_renders_the_form_with_editor_labels(): void
    {
        $response = $this->get('/contacto');

        $response->assertOk();
        $response->assertSee('Envianos un mensaje');
        $response->assertSee('name="name"', false);
        $response->assertSee('Consulta general');
        $response->assertSee('name="website"', false);
        $response->assertSee('name="_ts"', false);
        $response->assertDontSee('recaptcha_token');
    }

    public function test_valid_submission_is_stored_with_metadata_and_redirects_with_success(): void
    {
        $response = $this->from('/contacto')->post(route('forms.contact.store'), $this->payload());

        $response->assertRedirect('/contacto#contact-form');
        $response->assertSessionHas('contact_form_sent');

        $submission = ContactSubmission::first();
        $this->assertSame('Ana Pérez', $submission->name);
        $this->assertSame('Consulta general', $submission->subject);
        $this->assertSame($this->page->id, $submission->page_id);
        $this->assertSame('es', $submission->locale);
        $this->assertNotEmpty($submission->ip);
        $this->assertNull($submission->read_at);

        $this->followingRedirects()->post(route('forms.contact.store'), $this->payload())
            ->assertSee('¡Gracias! Recibimos tu mensaje');
    }

    public function test_invalid_submission_returns_translated_errors_and_stores_nothing(): void
    {
        $response = $this->from('/contacto')->post(route('forms.contact.store'), $this->payload(['name' => '', 'email' => 'nope', 'message' => 'corto']));

        $response->assertRedirect('/contacto');
        $response->assertSessionHasErrors(['name', 'email', 'message']);
        $this->assertSame('Ingresá tu nombre.', session('errors')->first('name'));
        $this->assertDatabaseCount('contact_submissions', 0);
    }

    public function test_honeypot_hits_are_dropped_silently(): void
    {
        $this->post(route('forms.contact.store'), $this->payload(['website' => 'http://spam.example']))
            ->assertRedirect('/contacto#contact-form')
            ->assertSessionHas('contact_form_sent');

        $this->post(route('forms.contact.store'), $this->payload(['_ts' => time()]))
            ->assertRedirect('/contacto#contact-form');

        $this->assertDatabaseCount('contact_submissions', 0);
    }

    public function test_recaptcha_is_enforced_when_keys_are_configured(): void
    {
        Setting::set('recaptcha_site_key', 'site-key');
        Setting::set('recaptcha_secret_key', 'secret-key');
        Setting::set('recaptcha_min_score', '0.5');

        $this->get('/contacto')->assertSee('recaptcha_token')->assertSee('recaptcha/api.js?render=site-key', false);

        Http::fake([
            \App\Services\Forms\Recaptcha::VERIFY_URL => Http::sequence()
                ->push(['success' => true, 'score' => 0.1, 'action' => 'contact'])
                ->push(['success' => true, 'score' => 0.9, 'action' => 'contact']),
        ]);

        $this->from('/contacto')->post(route('forms.contact.store'), $this->payload(['recaptcha_token' => 'low']))
            ->assertRedirect('/contacto')
            ->assertSessionHasErrors('recaptcha_token');
        $this->assertDatabaseCount('contact_submissions', 0);

        $this->post(route('forms.contact.store'), $this->payload(['recaptcha_token' => 'high']))
            ->assertSessionHas('contact_form_sent');
        $this->assertEqualsWithDelta(0.9, ContactSubmission::first()->recaptcha_score, 0.001);

        Http::assertSent(fn ($request) => $request['secret'] === 'secret-key' && $request['response'] === 'low');
    }

    public function test_missing_recaptcha_token_is_rejected_when_enabled(): void
    {
        Setting::set('recaptcha_site_key', 'site-key');
        Setting::set('recaptcha_secret_key', 'secret-key');
        Http::fake();

        $this->from('/contacto')->post(route('forms.contact.store'), $this->payload())
            ->assertSessionHasErrors('recaptcha_token');
        Http::assertNothingSent();
    }

    public function test_notification_email_is_sent_when_enabled_in_the_editor(): void
    {
        Mail::fake();
        Setting::set('mail_enabled', '1');
        $content = $this->page->content_json;
        $content['sections']['form']['notify_enabled'] = true;
        $content['sections']['form']['notify_email'] = 'inbox@example.com';
        $this->page->update(['content_json' => $content]);

        $this->post(route('forms.contact.store'), $this->payload());

        Mail::assertSent(ContactSubmissionReceived::class, function (ContactSubmissionReceived $mail) {
            return $mail->hasTo('inbox@example.com')
                && $mail->envelope()->replyTo[0]->address === 'ana@example.com'
                && str_contains($mail->render(), 'quisiera más información');
        });
        $this->assertNotNull(ContactSubmission::first()->notified_at);
    }

    public function test_notification_is_skipped_when_disabled_or_mail_off(): void
    {
        Mail::fake();

        $this->post(route('forms.contact.store'), $this->payload());
        Mail::assertNothingSent();

        $content = $this->page->content_json;
        $content['sections']['form']['notify_enabled'] = true;
        $content['sections']['form']['notify_email'] = 'inbox@example.com';
        $this->page->update(['content_json' => $content]);
        Setting::set('mail_enabled', '0');

        $this->post(route('forms.contact.store'), $this->payload());
        Mail::assertNothingSent();
        $this->assertNull(ContactSubmission::latest('id')->first()->notified_at);
    }

    public function test_submissions_are_rate_limited_per_ip(): void
    {
        foreach (range(1, 10) as $i) {
            $this->post(route('forms.contact.store'), $this->payload())->assertRedirect();
        }

        $this->post(route('forms.contact.store'), $this->payload())->assertStatus(429);
    }

    public function test_localized_route_exists_for_non_base_locales(): void
    {
        $this->assertSame('/en/forms/contact', parse_url(route('localized.forms.contact.store', ['locale' => 'en']), PHP_URL_PATH));
    }
}
