<?php

namespace Laraclaw\Services\Memory;

use Exception;
use Illuminate\Support\Facades\Log;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\MemorySourceType;
use Laraclaw\Models\Embedding;
use Laraclaw\Models\Thread;
use Laravel\Ai\Embeddings;

class EmbedContent
{
    /**
     * Inject the chunker that splits text and the extractor that pulls text out of attachments.
     */
    public function __construct(
        private readonly ContentChunker $chunker,
        private readonly TextExtractor $extractor,
    ) {}

    /**
     * Embed the user message, assistant response, and any attachments from a conversation turn.
     */
    public function run(Thread $thread, IncomingMessage $message, string $responseText, ?string $conversationId = null): void
    {
        $userId = $thread->user()?->getKey();

        if (! $userId) {
            return;
        }

        $context = ['connector' => $message->connector->value, 'key' => $message->key];

        $this->store($userId, $message->text ?? '', MemorySourceType::Message, $conversationId, [...$context, 'role' => 'user']);
        $this->store($userId, $responseText, MemorySourceType::Response, $conversationId, [...$context, 'role' => 'assistant']);

        foreach ($message->attachments as $attachment) {
            $text = $this->extractor->extract($attachment);

            if ($text !== null) {
                $this->store($userId, $text, MemorySourceType::Attachment, $message->uuid, [
                    'filename' => $attachment->filename,
                    'mime_type' => $attachment->mimeType,
                ]);
            }
        }
    }

    /**
     * Chunk text, deduplicate against existing embeddings, generate vectors in one batch,
     * and persist each new chunk.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function store(int $userId, string $content, MemorySourceType $sourceType, ?string $sourceId, array $metadata): void
    {
        if (trim($content) === '') {
            return;
        }

        $chunks = $this->chunker->chunk($content);
        $hashes = array_map(fn (string $c): string => hash('xxh128', $c), $chunks);

        $existing = Embedding::where('user_id', $userId)
            ->whereIn('content_hash', $hashes)
            ->pluck('content_hash')
            ->flip();

        // Keep original indices so we can look up the correct hash after filtering
        $newIndices = collect($chunks)
            ->keys()
            ->filter(fn (int $i): bool => ! $existing->has($hashes[$i]))
            ->values();

        if ($newIndices->isEmpty()) {
            return;
        }

        $newChunks = $newIndices->map(fn (int $i): string => $chunks[$i])->all();

        try {
            $vectors = Embeddings::for($newChunks)->generate();
        } catch (Exception $e) {
            Log::warning('Failed to generate embeddings', [
                'source_type' => $sourceType->value,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        foreach ($newIndices as $vectorIndex => $originalIndex) {
            Embedding::updateOrCreate(
                ['user_id' => $userId, 'content_hash' => $hashes[$originalIndex]],
                [
                    'source_type' => $sourceType->value,
                    'source_id' => $sourceId,
                    'content' => $chunks[$originalIndex],
                    'embedding' => $vectors->embeddings[$vectorIndex],
                    'metadata' => $metadata,
                ],
            );
        }
    }
}
