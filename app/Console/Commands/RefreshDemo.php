<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Simulation\DemoDataset;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('analytics:demo-refresh {--rebuild : Delete and re-simulate the demo games even if they are current}')]
#[Description('Keep the hosted demo current: ingest the events simulated since the last run, and rebuild the history when the day rolls over')]
class RefreshDemo extends Command
{
    public function handle(DemoDataset $dataset): int
    {
        if (! config('analytics.demo_mode')) {
            $this->error('analytics:demo-refresh deletes and re-creates the demo games, so it only runs with DEMO_MODE=true.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now('UTC');
        $dataset->ensureDemoUser();

        foreach ($dataset->games() as $definition) {
            $game = Game::where('slug', $definition['slug'])->first();

            if ($game === null || $this->option('rebuild') || ! $dataset->isCurrent($game, $definition, $now)) {
                // The simulated window moved, so its history no longer lines up: start the game again.
                $game?->delete();
                [$game] = $dataset->create($definition);
                $this->info("Rebuilding {$definition['name']} ({$definition['days']} days)...");
            }

            $accepted = $dataset->replay($game, $definition, $now);

            $this->line(sprintf('%s: %s new events', $definition['name'], number_format($accepted)));
        }

        return self::SUCCESS;
    }
}
