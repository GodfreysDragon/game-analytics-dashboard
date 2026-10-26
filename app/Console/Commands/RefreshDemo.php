<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Simulation\DemoDataset;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('analytics:demo-refresh {--rebuild : Re-simulate the demo games even if they are current} {--max-seconds= : Pause a rebuild after this many seconds per game and carry on with it on the next run}')]
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
        $maxSeconds = $this->option('max-seconds');
        $dataset->ensureDemoUser();

        foreach ($dataset->games() as $definition) {
            $live = Game::where('slug', $definition['slug'])->first();
            $build = $dataset->findBuild($definition);

            if ($build !== null && ($this->option('rebuild') || ! $dataset->isCurrent($build, $definition, $now))) {
                // A build for a window that has since moved, or one we were asked to start over.
                $dataset->discard($build);
                $build = null;
            }

            if ($build === null && $live !== null && ! $this->option('rebuild') && $dataset->isCurrent($live, $definition, $now)) {
                $result = $dataset->replay($live, $definition, $now);
                $this->line(sprintf('%s: %s new events', $definition['name'], number_format($result['accepted'])));

                continue;
            }

            // The simulated window moved, so the history no longer lines up: build it again out of
            // sight, and keep showing the old one until the new one is complete.
            if ($build === null) {
                [$build] = $dataset->create($definition, building: true);
                $this->info("Rebuilding {$definition['name']} ({$definition['days']} days)...");
            }

            $deadline = $maxSeconds === null ? null : CarbonImmutable::now()->addSeconds((int) $maxSeconds);
            $result = $dataset->replay($build, $definition, $now, $deadline);

            if (! $result['finished']) {
                $this->line(sprintf('%s: %s events built, continuing on the next run', $definition['name'], number_format($build->events()->count())));

                continue;
            }

            $dataset->publish($build, $definition);
            $this->line(sprintf('%s: rebuilt with %s events', $definition['name'], number_format($build->events()->count())));
        }

        return self::SUCCESS;
    }
}
