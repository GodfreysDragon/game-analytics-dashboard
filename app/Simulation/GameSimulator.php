<?php

namespace App\Simulation;

use Carbon\CarbonImmutable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Generates believable telemetry for a fictional mobile/PC tactics game, in the exact payload
 * shape the ingestion API accepts. Seeded, so a given seed always produces the same history.
 *
 * Built-in storyline so the dashboards have something to find:
 *  - retention decays on a power curve, a little weaker on Android;
 *  - a paid user-acquisition burst brings a spike of lower-intent installs;
 *  - a client update (1.4.0) fixes the tutorial, lifting tutorial completion and early retention.
 */
final class GameSimulator
{
    private const PLATFORMS = ['ios' => 45, 'android' => 42, 'pc' => 13];

    private const COUNTRIES = ['US' => 30, 'GB' => 9, 'DE' => 9, 'BR' => 10, 'JP' => 8, 'FR' => 6, 'CA' => 5, 'IN' => 9, 'KR' => 5, 'AU' => 4, 'MX' => 5];

    private const PRICES = ['4.99' => 45, '0.99' => 30, '9.99' => 17, '19.99' => 8];

    private const ITEMS = ['starter_pack', 'gem_pouch', 'hero_skin', 'battle_pass', 'energy_refill'];

    private Randomizer $random;

    /** @var array<string, array<string, mixed>> */
    private array $players = [];

    private int $playerSequence = 0;

    /**
     * @param  CarbonImmutable|null  $patchDate  day the tutorial fix ships (defaults to 14 days before the end)
     */
    public function __construct(
        int $seed = 1337,
        private float $baseInstallsPerDay = 40,
        private ?CarbonImmutable $patchDate = null,
        private string $playerPrefix = 'p',
    ) {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    /**
     * Simulate every day from $start to $end (inclusive), emitting events in chronological
     * batches. Events after $now are dropped so "today" stops at the present moment.
     *
     * @param  callable(list<array<string, mixed>>): void  $emit
     */
    public function backfill(CarbonImmutable $start, CarbonImmutable $end, callable $emit, ?CarbonImmutable $now = null, int $batchSize = 500): void
    {
        $now ??= CarbonImmutable::now('UTC');
        $this->patchDate ??= $end->subDays(14)->startOfDay();
        $campaignStart = $start->addDays((int) floor($start->diffInDays($end) * 0.4));

        for ($day = $start->startOfDay(); $day->lte($end); $day = $day->addDay()) {
            $events = [];

            $installs = $this->installsFor($day, $campaignStart);
            for ($i = 0; $i < $installs; $i++) {
                $this->newPlayer($day, $day->betweenIncluded($campaignStart, $campaignStart->addDays(2)));
            }

            foreach ($this->players as $id => $player) {
                if (! $this->isActiveOn($player, $day)) {
                    continue;
                }

                $sessions = 1 + ($this->chance(0.35 * $player['engagement']) ? 1 : 0) + ($this->chance(0.12 * $player['engagement']) ? 1 : 0);
                for ($s = 0; $s < $sessions; $s++) {
                    $startAt = $day->addSeconds($this->sessionStartSecond());
                    array_push($events, ...$this->session($id, $startAt));
                }
            }

            usort($events, fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);
            $events = array_values(array_filter($events, fn (array $e) => $e['timestamp'] <= $now->toIso8601ZuluString('millisecond')));

            foreach (array_chunk($events, $batchSize) as $chunk) {
                $emit($chunk);
            }
        }
    }

    /**
     * A few sessions ending right now - for the live tail. Mixes returning and new players.
     *
     * @return list<array<string, mixed>>
     */
    public function liveBatch(CarbonImmutable $now, int $sessions = 4): array
    {
        $events = [];

        for ($i = 0; $i < $sessions; $i++) {
            $returning = $this->players !== [] && $this->chance(0.7);
            $id = $returning
                ? $this->random->pickArrayKeys($this->players, 1)[0]
                : $this->newPlayer($now->startOfDay(), false);

            $session = $this->session($id, $now->subSeconds($this->random->getInt(60, 900)));
            $events = [...$events, ...array_filter($session, fn (array $e) => $e['timestamp'] <= $now->toIso8601ZuluString('millisecond'))];
        }

        usort($events, fn (array $a, array $b) => $a['timestamp'] <=> $b['timestamp']);

        return $events;
    }

    private function installsFor(CarbonImmutable $day, CarbonImmutable $campaignStart): int
    {
        $rate = $this->baseInstallsPerDay * ($day->isWeekend() ? 1.25 : 1.0);

        if ($day->betweenIncluded($campaignStart, $campaignStart->addDays(2))) {
            $rate *= 2.4;
        }

        return max(0, (int) round($rate * $this->random->getFloat(0.8, 1.2)));
    }

    private function newPlayer(CarbonImmutable $day, bool $fromCampaign): string
    {
        $platform = $this->weighted(self::PLATFORMS);
        $id = $this->playerPrefix.'_'.str_pad(base_convert((string) (++$this->playerSequence * 7919 + 104729), 10, 36), 6, '0', STR_PAD_LEFT);

        // Paid installs and Android skew slightly less engaged; engagement is log-normal-ish.
        $engagement = exp($this->gaussian(0, 0.45)) * ($platform === 'android' ? 0.88 : 1.0) * ($fromCampaign ? 0.7 : 1.0);

        $this->players[$id] = [
            'first_day' => $day,
            'platform' => $platform,
            'country' => $this->weighted(self::COUNTRIES),
            'engagement' => $engagement,
            'spender' => $this->chance(0.06 * min(2, $engagement)),
            'tutorial_done' => false,
            'onboarded' => false,
            'level' => 1,
            'churned' => false,
        ];

        return $id;
    }

    private function isActiveOn(array $player, CarbonImmutable $day): bool
    {
        if ($player['churned'] || $day->lt($player['first_day'])) {
            return false;
        }

        $age = (int) $player['first_day']->diffInDays($day);

        if ($age === 0) {
            return true;
        }

        $probability = 0.42 * $player['engagement'] * $age ** -0.45 * ($day->isWeekend() ? 1.1 : 1.0);

        // Players who never finished the tutorial rarely come back.
        if (! $player['tutorial_done']) {
            $probability *= 0.3;
        }

        return $this->chance(min(0.95, $probability));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function session(string $id, CarbonImmutable $startAt): array
    {
        $player = &$this->players[$id];
        $version = $this->patchDate !== null && $startAt->gte($this->patchDate) ? '1.4.0' : '1.3.2';
        $sessionId = $this->uuid();
        $clock = $startAt;
        $budget = (int) (60 * (3 + $this->random->getFloat(0, 1) * 18 * min(1.8, $player['engagement'])));
        $events = [];

        $emit = function (string $name, array $properties = [], ?float $value = null) use (&$events, &$clock, $id, $sessionId, $player, $version): void {
            $events[] = array_filter([
                'uuid' => $this->uuid(),
                'name' => $name,
                'player_id' => $id,
                'session_id' => $sessionId,
                'timestamp' => $clock->toIso8601ZuluString('millisecond'),
                'value' => $value,
                'properties' => $properties ?: null,
                'platform' => $player['platform'],
                'country' => $player['country'],
                'app_version' => $version,
            ], fn ($v) => $v !== null);
        };
        $advance = function (int $min, int $max) use (&$clock): void {
            $clock = $clock->addMilliseconds($this->random->getInt($min * 1000, $max * 1000));
        };

        if (! $player['onboarded']) {
            $emit('first_open', ['install_source' => $player['engagement'] < 0.75 ? 'paid' : 'organic']);
            $player['onboarded'] = true;
            $advance(1, 4);
        }

        $emit('session_start');
        $advance(2, 10);

        if (! $player['tutorial_done']) {
            $emit('tutorial_start');
            $completion = $version === '1.4.0' ? 0.8 : 0.6;

            foreach ([1, 2, 3] as $step) {
                $advance(20, 70);
                if (! $this->chance($step === 3 ? $completion / 0.92 : 0.96)) {
                    $emit('session_end', ['duration_s' => (int) $startAt->diffInSeconds($clock)]);

                    return $events;
                }
                $emit('tutorial_step', ['step' => $step]);
            }

            $advance(5, 20);
            $emit('tutorial_complete', ['duration_s' => (int) $startAt->diffInSeconds($clock)]);
            $player['tutorial_done'] = true;
        }

        while ($startAt->diffInSeconds($clock) < $budget) {
            $level = $player['level'];
            $emit('level_start', ['level' => $level]);
            $advance(60, 240);

            if ($this->chance(max(0.35, 0.82 - $level * 0.012))) {
                $emit('level_complete', ['level' => $level, 'stars' => $this->random->getInt(1, 3)]);
                $player['level']++;
            } else {
                $emit('level_fail', ['level' => $level, 'reason' => $this->chance(0.7) ? 'defeated' : 'quit']);
            }
            $advance(3, 15);

            if ($this->chance(0.12)) {
                $emit('ad_watched', ['placement' => $this->chance(0.6) ? 'revive' : 'double_reward']);
                $advance(15, 35);
            }

            if ($this->chance(0.08 + ($player['spender'] ? 0.12 : 0))) {
                $emit('store_open', ['source' => $this->chance(0.5) ? 'main_menu' : 'out_of_energy']);
                $advance(5, 40);

                if ($this->chance($player['spender'] ? 0.35 : 0.015)) {
                    $price = (float) $this->weighted(self::PRICES);
                    $emit('purchase', ['item' => self::ITEMS[$this->random->getInt(0, count(self::ITEMS) - 1)], 'currency' => 'USD'], $price);
                    $advance(3, 10);
                }
            }
        }

        $emit('session_end', ['duration_s' => (int) $startAt->diffInSeconds($clock)]);

        // Each session carries a small chance the player quits for good.
        if ($this->chance(0.04 / max(0.3, $player['engagement']))) {
            $player['churned'] = true;
        }

        return $events;
    }

    /**
     * Seconds after midnight UTC, weighted towards evenings.
     */
    private function sessionStartSecond(): int
    {
        $hour = (int) round(fmod(19 + $this->gaussian(0, 4.5) + 24, 24)) % 24;

        return $hour * 3600 + $this->random->getInt(0, 3599);
    }

    /**
     * A version-4 UUID drawn from the seeded generator, so replays are reproducible.
     */
    private function uuid(): string
    {
        $bytes = $this->random->getBytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function chance(float $probability): bool
    {
        return $this->random->getFloat(0, 1) < $probability;
    }

    private function gaussian(float $mean, float $sd): float
    {
        $u = max(1e-9, $this->random->getFloat(0, 1));
        $v = $this->random->getFloat(0, 1);

        return $mean + $sd * sqrt(-2 * log($u)) * cos(2 * M_PI * $v);
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function weighted(array $weights): string
    {
        $roll = $this->random->getInt(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }
}
