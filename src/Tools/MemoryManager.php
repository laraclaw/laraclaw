<?php

namespace Laraclaw\Tools;

use Laraclaw\Models\Embedding;
use Laravel\Ai\Tools\SimilaritySearch;

use function Laraclaw\Support\databaseUsesPgVector;

/**
 * Searches the memory for relevant past conversations and documents.
 * Uses native pgvector when available, falls back to PHP cosine similarity.
 */
class MemoryManager extends SimilaritySearch
{
    /**
     * Wire up the similarity search backend, picking pgvector when available and falling back to PHP cosine.
     */
    public function __construct()
    {
        $userId = (int) config('laraclaw.auth.admin_user_id');
        $minSimilarity = (float) config('laraclaw.memory.min_similarity', 0.3);
        $limit = (int) config('laraclaw.memory.max_results', 5);

        if (databaseUsesPgVector()) {
            $instance = SimilaritySearch::usingModel(
                model: Embedding::class,
                column: 'embedding',
                minSimilarity: $minSimilarity,
                limit: $limit,
                query: fn ($q) => $q->where('user_id', $userId),
            );

            parent::__construct($instance->using);
        } else {
            parent::__construct(
                fn (string $query): array => Embedding::searchSimilar($query, $userId, $limit, $minSimilarity),
            );
        }

        $this->withDescription(
            'Search long term memory for what was said in past conversations and documents, including ones outside the current chat. '
            . 'Call this before telling the user you do not know or do not remember something they may have told you before, '
            . 'and whenever they refer back to an earlier conversation. Pass the topic you are looking for as the query.'
        );
    }
}
