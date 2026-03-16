<?php

namespace LaraClaw\Models\Concerns;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;

trait SearchesWithCosineSimilarity
{
    private const int MAX_ROWS = 500;

    /**
     * Search for similar embeddings using PHP cosine similarity.
     * Fallback for databases without native vector support.
     *
     * @return list<array{content: string, score: float, metadata: array}>
     */
    public static function searchSimilar(
        string $query,
        int $userId,
        int $limit = 5,
        float $minSimilarity = 0.5,
    ): array {
        $queryEmbedding = Embeddings::for([$query])->cache()->generate()->first();

        $dbQuery = static::where('user_id', $userId);
        $count = $dbQuery->count();

        if ($count > self::MAX_ROWS) {
            Log::warning('Eloquent vector store row limit reached. Consider switching to the pgvector driver.', [
                'user_id' => $userId,
                'count' => $count,
                'limit' => self::MAX_ROWS,
            ]);
        }

        return $dbQuery->limit(self::MAX_ROWS)
            ->get(['content', 'embedding', 'metadata'])
            ->map(fn (self $row): array => [
                'content' => $row->content,
                'score' => self::cosineSimilarity($queryEmbedding, $row->embedding),
                'metadata' => $row->metadata ?? [],
            ])
            ->filter(fn (array $item): bool => $item['score'] >= $minSimilarity)
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<float>  $a
     * @param  array<float>  $b
     */
    private static function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0, $len = count($a); $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        if ($denominator === 0.0) {
            return 0.0;
        }

        return $dot / $denominator;
    }
}
