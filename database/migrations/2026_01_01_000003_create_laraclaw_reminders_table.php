<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laraclaw_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('connector');
            $table->string('key');
            $table->text('message');
            $table->dateTime('remind_at');
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['remind_at', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraclaw_reminders');
    }
};
