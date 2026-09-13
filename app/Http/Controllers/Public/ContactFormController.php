<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\ContactSubmissionRequest;
use App\Models\ContactSubmission;
use App\Models\Page;
use App\Services\Forms\SubmissionNotifier;
use Illuminate\Http\RedirectResponse;

/**
 * Receives the starter contact form. The flow every public form follows:
 * validate (incl. reCAPTCHA) → drop honeypot hits silently → store →
 * notify → redirect back to the page with a success flag. See docs/forms.md.
 */
class ContactFormController extends Controller
{
    public const SUCCESS_FLASH = 'contact_form_sent';

    public function store(ContactSubmissionRequest $request, SubmissionNotifier $notifier): RedirectResponse
    {
        $page = $request->filled('page_id') ? Page::find($request->integer('page_id')) : null;
        $redirect = redirect()->to(($page?->url() ?: '/').'#contact-form')->with(self::SUCCESS_FLASH, true);

        if ($request->isHoneypotTripped()) {
            return $redirect;
        }

        $submission = ContactSubmission::create([
            'page_id' => $page?->id,
            ...$request->safe()->only(['name', 'email', 'phone', 'subject', 'message']),
            ...$request->submissionMeta(),
        ]);

        $notifier->notify($submission, $page);

        return $redirect;
    }
}
