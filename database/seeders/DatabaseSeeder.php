<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Simulation\DemoDataset;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(DemoDataset $dataset): void
    {
        $dataset->ensureDemoUser();

        $now = CarbonImmutable::now('UTC');

        foreach ($dataset->games() as $definition) {
            if (Game::where('slug', $definition['slug'])->exists()) {
                $this->command?->warn("{$definition['name']} already exists - skipping. Run migrate:fresh --seed to rebuild the demo data.");

                continue;
            }

            $this->command?->info("Simulating {$definition['days']} days of {$definition['name']}...");

            [$game, $key] = $dataset->create($definition);
            $accepted = $dataset->replay($game, $definition, $now)['accepted'];

            $this->command?->info(sprintf('  %s events, %s players. Ingest key: %s', number_format($accepted), number_format($game->players()->count()), $key));
        }
    }
}
