<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitation extends Notification
{
    public function __construct(private string $firmName, private string $inviterName, private string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're invited to join {$this->firmName} on AUDIRA")
            ->greeting('Hello,')
            ->line("{$this->inviterName} has invited you to join {$this->firmName} on AUDIRA.")
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This invitation expires in '.config('audira.invitation_ttl_days').' days. If you were not expecting it, you can ignore this email.');
    }
}
