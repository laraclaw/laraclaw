<?php

use Laraclaw\Mail\ConnectorReply;

it('builds an envelope without threading headers when no in-reply-to', function () {
    $mailable = new ConnectorReply(body: '<p>Hello</p>');

    $envelope = $mailable->envelope();

    expect($envelope->using)->toBeEmpty();
});

it('adds threading headers when in-reply-to is provided', function () {
    $mailable = new ConnectorReply(body: '<p>Reply</p>', inReplyTo: '<msg-123@example.com>');

    $envelope = $mailable->envelope();

    expect($envelope->using)->toHaveCount(1);
});

it('returns html string content', function () {
    $mailable = new ConnectorReply(body: '<p>Test</p>');

    $content = $mailable->content();

    expect($content->htmlString)->toBe('<p>Test</p>');
});
