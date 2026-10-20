<?php

namespace App\Mail;

use App\Services\Reports\MonthlyReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MonthlyReportMail extends Mailable
{
    use Queueable;

    public function __construct(public int $year, public int $month, public string $title)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Marktschreier: '.$this->title);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.monthly', with: ['title' => $this->title]);
    }

    public function attachments(): array
    {
        return [Attachment::fromData(fn () => app(MonthlyReport::class)->pdf($this->year, $this->month), MonthlyReport::fileName($this->year, $this->month))->withMime('application/pdf')];
    }
}
