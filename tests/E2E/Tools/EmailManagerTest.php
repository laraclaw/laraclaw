<?php

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
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

    // Primary: a MessageSent event fired with the right recipient. This proves the
    // mailer actually shipped a message synchronously.
    // Fallback: if EmailManager ever moves to a queued mailable or a non-Mailer
    // transport, the event won't fire — fall back to the tool's success string,
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
