<?php

namespace App\Notifications;

use App\Models\Engagement;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** New engagement monitoring alerts for the engagement partner and manager (internal only). */
class MonitoringAlertsNotification extends Notification
{
    public function __construct(private Engagement $engagement, private Collection $alerts) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $m = (new MailMessage)->subject("AUDIRA: {$this->alerts->count()} new alert(s) on {$this->engagement->code}");
        foreach ($this->alerts as $a) {
            $m->line('• '.$a->message);
        }

        return $m->action('Open the engagement', route('engagements.show', $this->engagement));
    }
}
