<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a client contact that something is waiting in the portal. The email carries no audit content beyond the subject line. */
class ClientPortalUpdate extends Notification
{
    public function __construct(private string $firmName, private string $subject, private string $summary, private string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->firmName}: {$this->subject}")
            ->line($this->summary)
            ->action('Open the client portal', $this->url)
            ->line('Please do not reply to this email; use the portal so your audit team receives your response securely.');
    }
}
