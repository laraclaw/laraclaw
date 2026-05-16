<?php

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;

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
    expect($sent)->not->toBeEmpty();

    $recipients = array_keys($sent[0]->message->getTo());
    expect($recipients)->toContain($to);
});
