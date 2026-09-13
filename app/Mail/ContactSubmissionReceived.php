<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactSubmissionReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ContactSubmission $submission,
    ) {}

    public function envelope(): Envelope
    {
        $siteName = Setting::get('site_name') ?: config('app.name');

        return new Envelope(
            from: new Address(
                Setting::get('mail_from_address') ?: config('mail.from.address'),
                Setting::get('mail_from_name') ?: config('mail.from.name')
            ),
            replyTo: [new Address($this->submission->email, $this->submission->name)],
            subject: __('admin.submission_email_subject', ['site' => $siteName, 'name' => $this->submission->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-submission',
            with: [
                'submission' => $this->submission,
                'adminUrl' => route('admin.submissions.show', ['form' => 'contact', 'submission' => $this->submission->id]),
                'siteName' => Setting::get('site_name') ?: config('app.name'),
            ],
        );
    }
}
