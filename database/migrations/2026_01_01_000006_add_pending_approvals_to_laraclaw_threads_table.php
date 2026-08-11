<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laraclaw_threads', function (Blueprint $table) {
            // Tool calls the agent paused on, waiting for the user to approve them.
            // The laravel/ai conversation history is the source of truth for the
            // pause itself; this only records what we asked the user so the next
            // inbound message can be read as a reply to that question.
            $table->json('pending_approvals')->nullable()->after('persona');
        });
    }

    public function down(): void
    {
        Schema::table('laraclaw_threads', function (Blueprint $table) {
            $table->dropColumn('pending_approvals');
        });
    }
};
