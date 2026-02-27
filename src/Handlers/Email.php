<?php

namespace LaraClaw\Handlers;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use LaraClaw\Channels\EmailChannel;
use LaraClaw\Jobs\ProcessMessage;

class Email
{
    public function __invoke(MessageReceived $event): void
    {
        if (! config('laraclaw.channels.email.enabled')) {
            return;
        }

        $message = $event->message;

        // Prevent loops — ignore emails from ourselves
        $botEmail = config('imap.mailboxes.' . config('laraclaw.channels.email.imap.mailbox', 'default') . '.username');
        $fromEmail = $message->from()?->email() ?? 'unknown';

        if ($fromEmail === $botEmail) {
            return;
        }

        // Allow list — empty means block all
        $allowList = config('laraclaw.channels.email.sender_allow_list', []);

        if (empty($allowList) || ! in_array($fromEmail, $allowList)) {
            return;
        }

        // Authentication check — reject unless both DKIM and SPF pass
        if (config('laraclaw.channels.email.verify_sender_dkim_and_spf') && ! $this->passesAuthCheck($message)) {
            return;
        }

        $identifier = "email:{$fromEmail}";

        // If a tool is waiting for confirmation, push the reply and return early
        if (Redis::exists("awaiting_confirm:{$identifier}")) {
            $text = $message->text();
            if ($text !== null) {
                Redis::rpush("confirm:{$identifier}", $text);
            }

            return;
        }

        $channel = EmailChannel::fromMessage($message);

        if (blank($channel->text()) && $channel->attachments()->isEmpty()) {
            return;
        }

        ProcessMessage::dispatch($channel);
    }

    private function passesAuthCheck($message): bool
    {
        $authResults = $message->header('Authentication-Results')?->getRawValue() ?? '';

        $dkimPass = Str::contains($authResults, 'dkim=pass', ignoreCase: true);
        $spfPass = Str::contains($authResults, 'spf=pass', ignoreCase: true);

        return $dkimPass && $spfPass;
    }
}
