<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ActionRequiredMail extends Mailable
{
    public function __construct(public array $payload)
    {
    }

    public function envelope(): Envelope
    {
        $p = strtoupper($this->payload['priority'] ?? 'normal');

        return new Envelope(subject: "[LeadPilot · {$p}] " . ($this->payload['title'] ?? 'Action required'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.action_required');
    }
}
