<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortalInvitationNotification extends Notification
{
    public function __construct(private string $firmName, private string $clientName, private string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->firmName} has invited you to their AUDIRA client portal")
            ->greeting('Hello,')
            ->line("{$this->firmName} uses AUDIRA to request and receive documents for the audit of {$this->clientName}.")
            ->action('Set up your portal access', route('portal.invitation', $this->token))
            ->line('The portal is free for clients. This link expires in '.config('audira.invitation_ttl_days').' days.');
    }
}
