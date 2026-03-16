<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use function LaraClaw\Support\databaseUsesPgVector;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laraclaw_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 50);
            $table->string('source_id', 36)->nullable();
            $table->text('content');
            $table->string('content_hash', 64)->index();

            if (databaseUsesPgVector()) {
                // PostgreSQL with pgvector uses a native vector column with an HNSW index.
                // 1536 matches OpenAI text-embedding-3-small, adjust if your provider uses different dimensions.
                $table->vector('embedding', 1536)->vectorIndex();
            } else {
                // All other databases fall back to JSON with PHP cosine similarity at query time.
                $table->json('embedding')->nullable();
            }

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'source_type']);
            $table->unique(['user_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraclaw_embeddings');
    }
};
