<?php

namespace App\Simulation;

use App\Ingestion\EventIngestor;
use App\Models\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The demo games and their simulated history. The simulator is seeded, so replaying a game
 * on the same UTC day reproduces the same events (and uuids); events that happened since
 * the last replay are the only new ones. That is how the hosted demo stays current.
 */
final class DemoDataset
{
    /**
     * Fixed keys so the README's curl example and `analytics:simulate --http` work out of the box.
     * Only used in local and testing environments; anywhere else games get random keys.
     */
    public const DEMO_KEYS = [
        'starfall-tactics' => 'gk_demo_starfall_tactics_local_only',
        'pocket-harvest' => 'gk_demo_pocket_harvest_local_only',
    ];

    public function __construct(private EventIngestor $ingestor) {}

    /**
     * @return list<array{name: string, slug: string, days: int, installs_per_day: float, seed: int, funnels: list<array{name: string, steps: list<string>, window_minutes: int}>}>
     */
    public function games(): array
    {
        $days = max(1, (int) config('analytics.demo_days'));

        return [
            [
                'name' => 'Starfall Tactics',
                'slug' => 'starfall-tactics',
                'days' => $days,
                'installs_per_day' => 38,
                'seed' => 1337,
                'funnels' => [
                    ['name' => 'Onboarding', 'steps' => ['first_open', 'tutorial_start', 'tutorial_complete', 'level_complete'], 'window_minutes' => 60],
                    ['name' => 'Store conversion', 'steps' => ['session_start', 'store_open', 'purchase'], 'window_minutes' => 120],
                    ['name' => 'Fail → retry → win', 'steps' => ['level_fail', 'level_start', 'level_complete'], 'window_minutes' => 30],
                ],
            ],
            [
                'name' => 'Pocket Harvest',
                'slug' => 'pocket-harvest',
                'days' => min(30, $days),
                'installs_per_day' => 9,
                'seed' => 42,
                'funnels' => [
                    ['name' => 'Onboarding', 'steps' => ['first_open', 'tutorial_start', 'tutorial_complete'], 'window_minutes' => 60],
                ],
            ],
        ];
    }

    /**
     * Register a demo game with its funnels. Returns the game and its plaintext ingest key.
     *
     * @param  array{name: string, slug: string, funnels: list<array<string, mixed>>}  $definition
     * @return array{0: Game, 1: string}
     */
    public function create(array $definition): array
    {
        $game = new Game(['name' => $definition['name'], 'slug' => $definition['slug']]);

        if (app()->environment('local', 'testing')) {
            $key = self::DEMO_KEYS[$definition['slug']];
            $game->forceFill([
                'ingest_key_hash' => Game::hashKey($key),
                'ingest_key_prefix' => substr($key, 0, 10),
                'ingest_key_rotated_at' => now(),
            ])->save();
        } else {
            $key = $game->rotateIngestKey();
        }

        foreach ($definition['funnels'] as $funnel) {
            $game->funnels()->create($funnel);
        }

        return [$game, $key];
    }

    /**
     * Ingest the game's simulated history up to $now. Events at or before the previous replay
     * are skipped. When the window has moved (a new UTC day, or a new DEMO_DAYS) the whole
     * history is replayed, so the game should be rebuilt from scratch first: see isCurrent().
     *
     * @param  array{slug: string, days: int, installs_per_day: float, seed: int}  $definition
     * @return int events accepted
     */
    public function replay(Game $game, array $definition, CarbonImmutable $now): int
    {
        $firstDay = $now->subDays($definition['days'] - 1)->startOfDay()->toDateString();
        $previous = Cache::get($this->cacheKey($game));
        $after = ($previous['first_day'] ?? null) === $firstDay ? $previous['until'] : null;

        $simulator = new GameSimulator($definition['seed'], $definition['installs_per_day'], playerPrefix: substr($definition['slug'], 0, 2));
        $accepted = 0;

        $simulator->backfill(
            CarbonImmutable::parse($firstDay, 'UTC'),
            $now->startOfDay(),
            function (array $batch) use ($game, $after, &$accepted): void {
                if ($after !== null) {
                    $batch = array_values(array_filter($batch, fn (array $event) => $event['timestamp'] > $after));
                }

                if ($batch !== []) {
                    $accepted += $this->ingestor->ingest($game, $batch)->accepted;
                }
            },
            $now,
        );

        Cache::forever($this->cacheKey($game), ['first_day' => $firstDay, 'until' => $now->toIso8601ZuluString('millisecond')]);

        return $accepted;
    }

    /**
     * Whether the game's stored history was simulated for the window that applies at $now.
     *
     * @param  array{days: int}  $definition
     */
    public function isCurrent(Game $game, array $definition, CarbonImmutable $now): bool
    {
        return (Cache::get($this->cacheKey($game))['first_day'] ?? null) === $now->subDays($definition['days'] - 1)->startOfDay()->toDateString();
    }

    private function cacheKey(Game $game): string
    {
        return 'demo:replayed:'.$game->id;
    }
}
