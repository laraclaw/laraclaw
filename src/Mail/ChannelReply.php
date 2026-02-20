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
        $headers = [];

        if ($this->inReplyTo) {
            $headers['In-Reply-To'] = $this->inReplyTo;
            $headers['References'] = $this->inReplyTo;
        }

        return new Envelope(headers: $headers ? new \Illuminate\Mail\Mailables\Headers(text: $headers) : null);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->body);
    }
}
