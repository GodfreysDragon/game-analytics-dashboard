<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Only a SHA-256 hash of the ingest key is stored; the prefix lets admins tell keys apart.
            $table->char('ingest_key_hash', 64)->unique();
            $table->string('ingest_key_prefix', 16);
            $table->timestamp('ingest_key_rotated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
