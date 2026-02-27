<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laraclaw_user_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('account');
            $table->timestamps();

            $table->index('user_id');
            $table->unique(['channel', 'account']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laraclaw_user_accounts');
    }
};
