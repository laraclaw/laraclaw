<?php

namespace LaraClaw\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Mailable that sends an HTML reply from a channel, optionally threading it via In-Reply-To.
 */
class ChannelReply extends Mailable
{
    public function __construct(public string $body, public ?string $inReplyTo = null) {}

    /**
     * Build the envelope, adding In-Reply-To / References headers when replying to a thread.
     */
    public function envelope(): Envelope
    {
        if (! $this->inReplyTo) {
            return new Envelope;
        }

        $inReplyTo = $this->inReplyTo;

        return new Envelope(
            using: [function (Email $email) use ($inReplyTo): void {
                $email->getHeaders()->addTextHeader('In-Reply-To', $inReplyTo);
                $email->getHeaders()->addTextHeader('References', $inReplyTo);
            }]
        );
    }

    /**
     * Return the HTML string content for the email body.
     */
    public function content(): Content
    {
        return new Content(htmlString: $this->body);
    }
}
