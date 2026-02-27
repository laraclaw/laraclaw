<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laraclaw_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('channel');
            $table->string('key');

            // References a laravel/ai conversation. No foreign key constraint is placed
            // here because agent_conversations belongs to the laravel/ai package, so
            // coupling our migrations to it creates fragile package dependencies.
            $table->uuid('conversation_id')->nullable();
            $table->string('persona')->nullable();
            $table->timestamps();

            $table->unique(['channel', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraclaw_conversations');
    }
};
