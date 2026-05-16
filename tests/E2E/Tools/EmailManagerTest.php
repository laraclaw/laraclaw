<?php

beforeEach(function (): void {
    $this->requireDestructive();
    $this->requireEnv('ANTHROPIC_API_KEY', 'LARACLAW_SMTP_HOST', 'LARACLAW_SMTP_USERNAME', 'LARACLAW_SMTP_PASSWORD', 'LARACLAW_E2E_EMAIL_TO');
    $this->authenticatedUser();
});

it('sends a real email via the configured SMTP server', function (): void {
    $to = $this->envValue('LARACLAW_E2E_EMAIL_TO');

    $reply = $this->postMessage(
        "Use EmailManager to send an email to {$to} with subject 'Laraclaw E2E' and a one-sentence body about the test suite."
    );

    expect($reply['success'])->toBeTrue();
    $text = strtolower($reply['text']);
    expect(str_contains($text, 'sent') || str_contains($text, 'email'))->toBeTrue();
});
