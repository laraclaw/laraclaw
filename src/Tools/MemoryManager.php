<?php

namespace LaraClaw\Tools;

use LaraClaw\Models\Embedding;
use Laravel\Ai\Tools\SimilaritySearch;

use function LaraClaw\Support\databaseUsesPgVector;

/**
 * Searches the memory for relevant past conversations and documents.
 * Uses native pgvector when available, falls back to PHP cosine similarity.
 */
class MemoryManager extends SimilaritySearch
{
    public function __construct()
    {
        $userId = (int) config('laraclaw.auth.admin_user_id');
        $minSimilarity = (float) config('laraclaw.memory.min_similarity', 0.5);
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

        $this->withDescription('Search the memory for relevant past conversations and documents.');
    }
}
