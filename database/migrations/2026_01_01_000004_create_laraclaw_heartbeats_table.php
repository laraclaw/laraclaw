<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laraclaw_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel_identifier'); // e.g. "telegram:8509314858", "slack:C01ABC:1234.5678"
            $table->text('message');
            $table->string('cron'); // e.g. "0 9 * * 1" (every Monday at 9am)
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraclaw_heartbeats');
    }
};
