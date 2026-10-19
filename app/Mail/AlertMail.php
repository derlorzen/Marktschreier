<?php

namespace App\Mail;

use App\Models\Alert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AlertMail extends Mailable
{
    use Queueable;

    public function __construct(public Alert $alert)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Marktschreier: '.$this->alert->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.alert');
    }
}
