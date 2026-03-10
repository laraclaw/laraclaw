<?php

namespace LaraClaw\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Channels\ApiChannel;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Models\Thread;
use LaraClaw\Models\UserAccount;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Services\Attachments;

use function LaraClaw\Support\logAgentUsage;

/**
 * Handle incoming API requests authenticated via Sanctum.
 */
class ApiController extends Controller
{
    public function __construct(
        private readonly Attachments $attachments,
        private readonly CommandRegistry $commands,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'text' => ['required_without:attachments', 'nullable', 'string'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file'],
        ]);

        $user = $request->user();

        UserAccount::firstOrCreate(
            ['channel' => ChannelType::Api, 'account' => $user->getAuthIdentifier()],
            ['user_id' => $user->getAuthIdentifier()],
        );

        $incomingMessage = ApiChannel::createIncomingMessageFrom(
            text: $request->input('text'),
            userId: $user->getAuthIdentifier(),
            files: $request->file('attachments', []),
            attachments: $this->attachments,
        );

        $thread = Thread::forMessage($incomingMessage);

        // Handle commands
        if ($command = $this->commands->match($incomingMessage->text ?? '')) {
            $command->handle($incomingMessage, $thread);

            return response()->json(['success' => true]);
        }

        $agent = resolve(ChatBotAgent::class, ['message' => $incomingMessage, 'thread' => $thread]);
        $response = $agent->prompt(...$incomingMessage->toAgentInput());

        logAgentUsage('api', $response->usage);
        $thread->update(['conversation_id' => $response->conversationId]);

        $outboundAttachments = $this->attachments->outbound($incomingMessage->uuid)->getAll();

        return response()->json([
            'success' => true,
            'text' => $response->text,
            'conversation_id' => $response->conversationId,
            'attachments' => $outboundAttachments
                ->map(fn (Attachment $a) => [
                    'filename' => $a->filename,
                    'mime_type' => $a->mimeType,
                    'path' => $a->path,
                ])
                ->values()
                ->all(),
        ]);
    }
}
