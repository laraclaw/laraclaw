<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->renameIfExists('laraclaw_accounts');
        $this->renameIfExists('laraclaw_threads');
        $this->renameIfExists('laraclaw_reminders');
        $this->renameIfExists('laraclaw_heartbeats');
    }

    public function down(): void
    {
        foreach (['laraclaw_accounts', 'laraclaw_threads', 'laraclaw_reminders', 'laraclaw_heartbeats'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'connector')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->renameColumn('connector', 'channel');
                });
            }
        }
    }

    private function renameIfExists(string $table): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'channel')) {
            Schema::table($table, function (Blueprint $table) {
                $table->renameColumn('channel', 'connector');
            });
        }
    }
};
