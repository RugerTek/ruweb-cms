<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class VerifyPendingEmail extends Notification
{
    public function __construct(private string $token, private User $user) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('admin.profile.email.verify', now()->addHour(), ['user' => $this->user->id, 'token' => $this->token]);

        return (new MailMessage)->subject(__('security.verify_email'))->line(__('security.verify_email_hint'))
            ->action(__('security.verify_email'), $url)->line(__('security.verify_email_ignore'));
    }
}
