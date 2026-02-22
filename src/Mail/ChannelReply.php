<?php

namespace LaraClaw\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ChannelReply extends Mailable
{
    public function __construct(
        public string $body,
        public ?string $inReplyTo = null,
    ) {}

    public function envelope(): Envelope
    {
        if (! $this->inReplyTo) {
            return new Envelope();
        }

        $inReplyTo = $this->inReplyTo;

        return new Envelope(
            using: [function (\Symfony\Component\Mime\Email $email) use ($inReplyTo) {
                $email->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
                $email->getHeaders()->addTextHeader('References', $inReplyTo);
            }]
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->body);
    }
}
