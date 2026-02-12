<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ChannelReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $body, private ?string $inReplyTo = null) {}

    public function build(): self
    {
        $this->text('mail.channel-reply');

        if ($this->inReplyTo) {
            $this->withSymfonyMessage(function ($message) {
                $message->getHeaders()
                    ->addTextHeader('In-Reply-To', $this->inReplyTo);
                $message->getHeaders()
                    ->addTextHeader('References', $this->inReplyTo);
            });
        }

        return $this;
    }
}
