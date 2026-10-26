<?php

namespace App\Simulation;

use App\Ingestion\EventIngestor;
use App\Models\Game;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The demo games and their simulated history. The simulator is seeded, so replaying a game
 * on the same UTC day reproduces the same events (and uuids); events that happened since
 * the last replay are the only new ones. That is how the hosted demo stays current.
 *
 * When the window moves, the new history is built as a hidden copy of the game, over as many
 * runs as it takes, and swapped in once it is complete, so visitors never see it half built.
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
     * The shared account visitors sign in with.
     */
    public function ensureDemoUser(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Demo Admin', 'password' => 'password'],
        );
    }

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
     * A $building copy gets its own slug and stays hidden until publish() swaps it in.
     *
     * @param  array{name: string, slug: string, funnels: list<array<string, mixed>>}  $definition
     * @return array{0: Game, 1: string}
     */
    public function create(array $definition, bool $building = false): array
    {
        $game = new Game(['name' => $definition['name'], 'slug' => $building ? $this->buildSlug($definition) : $definition['slug']]);
        $game->building = $building;

        $key = ! $building && app()->environment('local', 'testing')
            ? $this->useDemoKey($game)
            : $game->rotateIngestKey();

        foreach ($definition['funnels'] as $funnel) {
            $game->funnels()->create($funnel);
        }

        return [$game, $key];
    }

    /**
     * The hidden copy of the game that is being built, if a build is under way.
     *
     * @param  array{slug: string}  $definition
     */
    public function findBuild(array $definition): ?Game
    {
        return Game::query()->where('slug', $this->buildSlug($definition))->where('building', true)->first();
    }

    /**
     * Replace the game with its finished build. One transaction, so visitors see either the
     * old history or the new one.
     *
     * @param  array{slug: string}  $definition
     */
    public function publish(Game $build, array $definition): void
    {
        $live = Game::query()->where('slug', $definition['slug'])->first();

        DB::transaction(function () use ($build, $definition, $live): void {
            $live?->delete();
            $build->forceFill(['slug' => $definition['slug'], 'building' => false])->save();

            if (app()->environment('local', 'testing')) {
                $this->useDemoKey($build);
            }
        });

        if ($live !== null) {
            Cache::forget($this->cacheKey($live));
        }
    }

    /**
     * Delete a game along with the record of its last replay.
     */
    public function discard(Game $game): void
    {
        $game->delete();
        Cache::forget($this->cacheKey($game));
    }

    /**
     * Ingest the game's simulated history up to $now. Events at or before the previous replay
     * are skipped. When the window has moved (a new UTC day, or a new DEMO_DAYS) the whole
     * history is replayed, so the game should be rebuilt from scratch first: see isCurrent().
     *
     * Once the $deadline has passed, the replay stops between batches without finishing, and
     * the next replay carries on after the last stored event. Every replay stores at least one batch.
     *
     * @param  array{slug: string, days: int, installs_per_day: float, seed: int}  $definition
     * @return array{accepted: int, finished: bool}
     */
    public function replay(Game $game, array $definition, CarbonImmutable $now, ?CarbonImmutable $deadline = null): array
    {
        $firstDay = $now->subDays($definition['days'] - 1)->startOfDay()->toDateString();
        $previous = Cache::get($this->cacheKey($game));
        $after = ($previous['first_day'] ?? null) === $firstDay ? $previous['until'] : null;

        $simulator = new GameSimulator($definition['seed'], $definition['installs_per_day'], playerPrefix: substr($definition['slug'], 0, 2));
        $accepted = 0;
        $until = $after;
        $finished = true;

        $simulator->backfill(
            CarbonImmutable::parse($firstDay, 'UTC'),
            $now->startOfDay(),
            function (array $batch) use ($game, $after, $deadline, &$accepted, &$until, &$finished): void {
                if ($after !== null) {
                    $batch = array_values(array_filter($batch, fn (array $event) => $event['timestamp'] > $after));
                }

                if ($batch === [] || ! $finished) {
                    return;
                }

                // Batches arrive in time order. A batch that starts at the last stored timestamp
                // still goes in, because the next replay only takes events after it.
                if ($deadline !== null && $until !== $after && $batch[0]['timestamp'] > $until && CarbonImmutable::now()->gte($deadline)) {
                    $finished = false;

                    return;
                }

                $accepted += $this->ingestor->ingest($game, $batch)->accepted;
                $until = end($batch)['timestamp'];
            },
            $now,
        );

        Cache::forever($this->cacheKey($game), ['first_day' => $firstDay, 'until' => $finished ? $now->toIso8601ZuluString('millisecond') : $until]);

        return ['accepted' => $accepted, 'finished' => $finished];
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

    /**
     * Give the game its fixed local key, so the README's examples work against the seeded data.
     */
    private function useDemoKey(Game $game): string
    {
        $key = self::DEMO_KEYS[$game->slug];

        $game->forceFill([
            'ingest_key_hash' => Game::hashKey($key),
            'ingest_key_prefix' => substr($key, 0, 10),
            'ingest_key_rotated_at' => now(),
        ])->save();

        return $key;
    }

    /**
     * @param  array{slug: string}  $definition
     */
    private function buildSlug(array $definition): string
    {
        return $definition['slug'].'-next';
    }

    private function cacheKey(Game $game): string
    {
        return 'demo:replayed:'.$game->id;
    }
}
