<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per player per active day, maintained at ingestion time. DAU is a COUNT over
        // activity_on and cohort retention is a GROUP BY (cohort_on, day_index) - no date maths in SQL.
        Schema::create('player_activity_days', function (Blueprint $table) {
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->date('activity_on');
            $table->date('cohort_on');
            $table->unsignedSmallInteger('day_index');

            $table->primary(['player_id', 'activity_on']);
            $table->index(['game_id', 'activity_on']);
            $table->index(['game_id', 'cohort_on', 'day_index']);
        });

        Schema::create('game_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->string('session_key', 128);
            $table->dateTime('started_at', 3);
            $table->date('started_on');
            $table->dateTime('ended_at', 3);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('event_count')->default(0);

            // Clients only have to keep session ids unique per player, not across the whole game.
            $table->unique(['player_id', 'session_key']);
            $table->index(['game_id', 'started_on']);
            $table->index(['player_id', 'started_at']);
        });

        Schema::create('funnels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('steps');
            $table->unsignedInteger('window_minutes')->default(1440);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnels');
        Schema::dropIfExists('game_sessions');
        Schema::dropIfExists('player_activity_days');
    }
};
