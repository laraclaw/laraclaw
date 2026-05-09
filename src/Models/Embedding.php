<?php

namespace Laraclaw\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laraclaw\Enums\MemorySourceType;
use Laraclaw\Models\Concerns\SearchesWithCosineSimilarity;
use Override;

/**
 * Eloquent model representing a stored embedding vector for memory retrieval.
 */
class Embedding extends Model
{
    use SearchesWithCosineSimilarity;

    const string TABLE = 'laraclaw_embeddings';

    protected $table = self::TABLE;

    protected $fillable = [
        'user_id',
        'source_type',
        'source_id',
        'content',
        'content_hash',
        'embedding',
        'metadata',
    ];

    /**
     * The user who owns this embedding.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('laraclaw.auth.user_model'));
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'source_type' => MemorySourceType::class,
            'embedding' => 'array',
            'metadata' => 'array',
        ];
    }
}
