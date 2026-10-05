<?php

namespace App\Console\Commands;

use App\Ingestion\EventIngestor;
use App\Models\Game;
use App\Simulation\GameSimulator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('analytics:simulate
    {game=starfall-tactics : Slug of the game to send events for}
    {--http= : Base URL of the app; sends batches through the real ingestion endpoint instead of writing directly}
    {--key= : Ingest key to use with --http}
    {--interval=3 : Seconds between batches}
    {--sessions=4 : Sessions per batch}
    {--iterations=0 : Stop after this many batches (0 = run until interrupted)}')]
#[Description('Stream live, simulated player traffic so the dashboard has something happening right now')]
class SimulateTraffic extends Command
{
    public function handle(EventIngestor $ingestor): int
    {
        $game = Game::where('slug', $this->argument('game'))->first();

        if ($game === null) {
            $this->error('Unknown game slug. Run `php artisan migrate:fresh --seed` first, or pass a slug from the games table.');

            return self::FAILURE;
        }

        $url = $this->option('http') ? rtrim((string) $this->option('http'), '/').'/api/v1/events' : null;

        if ($url !== null && ! $this->option('key')) {
            $this->error('--http needs --key (the plaintext ingest key shown when the game was seeded or its key rotated).');

            return self::FAILURE;
        }

        $simulator = new GameSimulator(random_int(1, PHP_INT_MAX), playerPrefix: 'live');
        $iterations = (int) $this->option('iterations');

        $this->info("Sending live traffic for {$game->name}".($url ? " to {$url}" : ' (direct)').'. Ctrl+C to stop.');

        for ($i = 1; $iterations === 0 || $i <= $iterations; $i++) {
            $batch = $simulator->liveBatch(CarbonImmutable::now('UTC'), (int) $this->option('sessions'));

            if ($url !== null) {
                $response = Http::withToken((string) $this->option('key'))->acceptJson()->timeout(10)->post($url, ['events' => $batch]);
                $summary = $response->successful()
                    ? "accepted {$response->json('accepted')}"
                    : "HTTP {$response->status()}: ".$response->json('message', $response->body());
            } else {
                $summary = 'accepted '.$ingestor->ingest($game, $batch)->accepted;
            }

            $this->line(sprintf('[%s] %d events, %s', now()->format('H:i:s'), count($batch), $summary));

            if ($iterations === 0 || $i < $iterations) {
                sleep(max(1, (int) $this->option('interval')));
            }
        }

        return self::SUCCESS;
    }
}
