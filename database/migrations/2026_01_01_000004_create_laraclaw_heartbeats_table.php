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
            $table->string('connector');
            $table->string('key');
            $table->text('prompt');

            // Stores a 5-field cron expression that controls when the heartbeat prompt
            // fires. Accepts standard cron syntax, e.g. "0 9 * * 1" for every Monday
            // at 9am. Expressions are evaluated against the application timezone.
            $table->string('cron');
            $table->boolean('is_active')->default(true)->index();
            $table->dateTime('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraclaw_heartbeats');
    }
};
