<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            // Client-generated id: retries of the same batch are deduplicated on (game_id, uuid).
            $table->uuid('uuid');
            $table->string('name', 64);
            $table->string('session_key', 128)->nullable();
            $table->decimal('value', 12, 4)->nullable();
            $table->json('properties')->nullable();
            $table->dateTime('occurred_at', 3);
            // Denormalised UTC date so daily grouping is a plain indexed GROUP BY on every database driver.
            $table->date('occurred_on');
            $table->dateTime('received_at', 3);

            $table->unique(['game_id', 'uuid']);
            $table->index(['game_id', 'occurred_on', 'name']);
            $table->index(['game_id', 'name', 'occurred_at']);
            $table->index(['player_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
