<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** In-app (database) notification. Email is sent separately by Notifier so delivery can be recorded honestly. */
class ActionRequired extends Notification
{
    public function __construct(public array $payload)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }
}
