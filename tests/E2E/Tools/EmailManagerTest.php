<?php

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;

beforeEach(function (): void {
    $this->requireDestructive();
    $this->requireEnv('ANTHROPIC_API_KEY', 'LARACLAW_SMTP_HOST', 'LARACLAW_SMTP_USERNAME', 'LARACLAW_SMTP_PASSWORD', 'LARACLAW_E2E_EMAIL_TO');
    $this->authenticatedUser();
});

it('sends a real email via the configured SMTP server', function (): void {
    $to = $this->envValue('LARACLAW_E2E_EMAIL_TO');
    $sent = [];

    Event::listen(MessageSent::class, function (MessageSent $event) use (&$sent): void {
        $sent[] = $event;
    });

    $reply = $this->postMessage(
        "Use EmailManager to send an email to {$to} with subject 'Laraclaw E2E' and a one-sentence body about the test suite."
    );

    expect($reply['success'])->toBeTrue();

    // MessageSent fires on the log and array transports too, so the event alone says
    // nothing about delivery. Pin the transport first: once it is a real SMTP
    // transport, Symfony throws on rejection, so a send that returned without
    // throwing means the server accepted the message.
    expect(Mail::mailer()->getSymfonyTransport())->toBeInstanceOf(EsmtpTransport::class);

    // Fallback: if EmailManager ever moves to a queued mailable or a non Mailer
    // transport, the event will not fire. Fall back to the tool's success string,
    // which is the literal "Email sent to <to>..." returned by EmailManager::send().
    if ($sent !== []) {
        // getTo() hands back a plain list of Address objects, so the addresses live
        // in the values rather than the keys.
        $recipients = collect($sent[0]->message->getTo())
            ->map(fn (Address $address): string => $address->getAddress())
            ->all();

        expect($recipients)->toContain($to);
    } else {
        expect($reply['text'])->toMatch('/email sent to[^.]*' . preg_quote($to, '/') . '/i');
    }
});
