<?php

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Game>
 */
class GameFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        $key = Game::KEY_PREFIX.Str::random(40);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'ingest_key_hash' => Game::hashKey($key),
            'ingest_key_prefix' => substr($key, 0, 10),
        ];
    }

    /**
     * Use a known plaintext ingest key.
     */
    public function withKey(string $plainKey): static
    {
        return $this->state(fn () => [
            'ingest_key_hash' => Game::hashKey($plainKey),
            'ingest_key_prefix' => substr($plainKey, 0, 10),
        ]);
    }
}
