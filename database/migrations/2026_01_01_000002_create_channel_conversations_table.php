<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_conversations', function (Blueprint $table) {
            $table->string('identifier')->primary(); // e.g. "telegram:-12345", "slack:C01:1234567890.123"
            $table->uuid('conversation_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_conversations');
    }
};
