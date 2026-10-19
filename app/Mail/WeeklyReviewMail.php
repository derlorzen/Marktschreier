<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WeeklyReviewMail extends Mailable
{
    use Queueable;

    /** @param array<string, mixed> $review */
    public function __construct(public array $review)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Marktschreier: Deine Woche ('.$this->review['period'].')');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly');
    }
}
