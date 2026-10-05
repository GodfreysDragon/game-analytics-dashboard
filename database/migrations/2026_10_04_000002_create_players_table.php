<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 128);
            $table->string('platform', 16)->nullable();
            $table->char('country', 2)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->dateTime('first_seen_at', 3);
            $table->date('first_seen_on');
            $table->dateTime('last_seen_at', 3);
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('session_count')->default(0);
            $table->decimal('lifetime_value', 12, 4)->default(0);
            $table->timestamps();

            $table->unique(['game_id', 'external_id']);
            $table->index(['game_id', 'first_seen_on']);
            $table->index(['game_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
