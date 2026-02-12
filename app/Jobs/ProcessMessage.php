<?php

namespace App\Jobs;

use App\Ai\Agents\ChatBot;
use App\Ai\ConversationParticipant;
use App\Channels\Contracts\ChannelDriver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private ChannelDriver $driver,
    ) {}

    public function handle(): void
    {
        $text = $this->driver->text() ?? '';
        $participant = new ConversationParticipant($this->driver->platform() . ':' . $this->driver->senderId());

        if (strtolower(trim($text)) === '!new') {
            ChatBot::make()->forUser($participant)->prompt($text);
            $this->driver->reply('Conversation reset. How can I help you?');

            return;
        }

        $response = ChatBot::make()->continueLastConversation(as: $participant)->prompt($text);

        $this->driver->reply((string) $response);
    }
}
