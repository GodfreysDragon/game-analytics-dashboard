<?php

namespace Database\Seeders;

use App\Ingestion\EventIngestor;
use App\Models\Game;
use App\Models\User;
use App\Simulation\GameSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Fixed keys so the README's curl example and `analytics:simulate --http` work out of the box.
     * Only used outside production; production games get random keys.
     */
    public const DEMO_KEYS = [
        'starfall-tactics' => 'gk_demo_starfall_tactics_local_only',
        'pocket-harvest' => 'gk_demo_pocket_harvest_local_only',
    ];

    public function run(EventIngestor $ingestor): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Demo Admin', 'password' => 'password'],
        );

        $this->seedGame($ingestor, 'Starfall Tactics', days: 60, installsPerDay: 38, seed: 1337, funnels: [
            ['name' => 'Onboarding', 'steps' => ['first_open', 'tutorial_start', 'tutorial_complete', 'level_complete'], 'window_minutes' => 60],
            ['name' => 'Store conversion', 'steps' => ['session_start', 'store_open', 'purchase'], 'window_minutes' => 120],
            ['name' => 'Fail → retry → win', 'steps' => ['level_fail', 'level_start', 'level_complete'], 'window_minutes' => 30],
        ]);

        $this->seedGame($ingestor, 'Pocket Harvest', days: 30, installsPerDay: 9, seed: 42, funnels: [
            ['name' => 'Onboarding', 'steps' => ['first_open', 'tutorial_start', 'tutorial_complete'], 'window_minutes' => 60],
        ]);
    }

    /**
     * @param  list<array{name: string, steps: list<string>, window_minutes: int}>  $funnels
     */
    private function seedGame(EventIngestor $ingestor, string $name, int $days, float $installsPerDay, int $seed, array $funnels): void
    {
        $slug = Str::slug($name);

        if (Game::where('slug', $slug)->exists()) {
            $this->command?->warn("{$name} already exists - skipping. Run migrate:fresh --seed to rebuild the demo data.");

            return;
        }

        $game = new Game(['name' => $name, 'slug' => $slug]);
        $key = app()->isProduction() ? $game->rotateIngestKey() : $this->useDemoKey($game, self::DEMO_KEYS[$slug]);

        foreach ($funnels as $funnel) {
            $game->funnels()->create($funnel);
        }

        $now = CarbonImmutable::now('UTC');
        $simulator = new GameSimulator($seed, $installsPerDay, playerPrefix: substr($slug, 0, 2));
        $accepted = 0;

        $this->command?->info("Simulating {$days} days of {$name}...");

        $simulator->backfill(
            $now->subDays($days - 1)->startOfDay(),
            $now->startOfDay(),
            function (array $batch) use ($ingestor, $game, &$accepted): void {
                $accepted += $ingestor->ingest($game, $batch)->accepted;
            },
            $now,
        );

        $this->command?->info(sprintf('  %s events, %s players. Ingest key: %s', number_format($accepted), number_format($game->players()->count()), $key));
    }

    private function useDemoKey(Game $game, string $key): string
    {
        $game->forceFill([
            'ingest_key_hash' => Game::hashKey($key),
            'ingest_key_prefix' => substr($key, 0, 10),
            'ingest_key_rotated_at' => now(),
        ])->save();

        return $key;
    }
}
