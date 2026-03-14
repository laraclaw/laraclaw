<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laraclaw_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('connector');
            $table->string('account');
            $table->timestamps();

            $table->index('user_id');
            $table->unique(['connector', 'account']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraclaw_accounts');
    }
};
