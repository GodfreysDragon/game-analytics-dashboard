<?php

namespace App\Analytics;

use App\Models\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ordered conversion funnel. A player enters on any occurrence of the first step inside the
 * date range; each later step must happen after the previous one and within the conversion
 * window measured from entry. Of all a player's entries, the one that got furthest counts.
 */
final class FunnelReport
{
    /**
     * @param  list<string>  $steps
     * @return array<string, mixed>
     */
    public function build(Game $game, array $steps, int $windowMinutes, DateRange $range, ?string $platform = null): array
    {
        $windowMs = $windowMinutes * 60_000;
        $rangeStartMs = $range->from->getTimestampMs();
        $rangeEndMs = $range->to->addDay()->getTimestampMs();

        $rows = DB::table('events AS e')
            ->join('players AS p', 'p.id', '=', 'e.player_id')
            ->where('e.game_id', $game->id)
            ->whereIn('e.name', array_unique($steps))
            ->where('e.occurred_at', '>=', $range->from->toDateTimeString())
            ->where('e.occurred_at', '<', $range->to->addDay()->addMinutes($windowMinutes)->toDateTimeString())
            ->when($platform, fn ($query) => $query->where('p.platform', $platform))
            ->orderBy('e.player_id')
            ->orderBy('e.occurred_at')
            ->orderBy('e.id')
            ->select('e.player_id', 'e.name', 'e.occurred_at', 'p.platform')
            ->cursor();

        $stepCount = count($steps);
        $reached = array_fill(0, $stepCount, 0);
        $byPlatform = [];
        $durations = array_fill(0, $stepCount, []);

        $tally = function (array $events, ?string $playerPlatform) use ($steps, $stepCount, $windowMs, $rangeStartMs, $rangeEndMs, &$reached, &$byPlatform, &$durations): void {
            $best = $this->bestPath($events, $steps, $windowMs, $rangeStartMs, $rangeEndMs);

            if ($best === []) {
                return;
            }

            $label = $playerPlatform ?? 'unknown';
            $byPlatform[$label] ??= array_fill(0, $stepCount, 0);

            foreach ($best as $index => $timestamp) {
                $reached[$index]++;
                $byPlatform[$label][$index]++;

                if ($index > 0) {
                    $durations[$index][] = ($timestamp - $best[$index - 1]) / 1000;
                }
            }
        };

        $currentPlayer = null;
        $currentPlatform = null;
        $events = [];

        foreach ($rows as $row) {
            if ($row->player_id !== $currentPlayer) {
                if ($events !== []) {
                    $tally($events, $currentPlatform);
                }
                $currentPlayer = $row->player_id;
                $currentPlatform = $row->platform;
                $events = [];
            }

            $events[] = [$row->name, CarbonImmutable::parse($row->occurred_at, 'UTC')->getTimestampMs()];
        }

        if ($events !== []) {
            $tally($events, $currentPlatform);
        }

        $entered = $reached[0];

        $result = [];
        foreach ($steps as $index => $name) {
            $previous = $index === 0 ? $entered : $reached[$index - 1];

            $result[] = [
                'name' => $name,
                'players' => $reached[$index],
                'conversion' => $entered > 0 ? round($reached[$index] / $entered, 4) : null,
                'step_conversion' => $previous > 0 ? round($reached[$index] / $previous, 4) : null,
                'dropped' => $previous - $reached[$index],
                'median_seconds' => $index === 0 ? null : $this->median($durations[$index]),
            ];
        }

        uasort($byPlatform, fn (array $a, array $b) => $b[0] <=> $a[0]);

        return [
            'range' => $range->toArray(),
            'window_minutes' => $windowMinutes,
            'steps' => $result,
            'breakdown' => array_map(
                fn (string $label, array $counts) => [
                    'label' => $label,
                    'counts' => $counts,
                    'conversion' => $counts[0] > 0 ? round($counts[$stepCount - 1] / $counts[0], 4) : null,
                ],
                array_keys($byPlatform),
                array_values($byPlatform),
            ),
        ];
    }

    /**
     * Timestamps of each step reached on the player's furthest-reaching attempt.
     *
     * @param  list<array{0: string, 1: int}>  $events  [name, timestamp ms], chronological
     * @param  list<string>  $steps
     * @return list<int>
     */
    private function bestPath(array $events, array $steps, int $windowMs, int $rangeStartMs, int $rangeEndMs): array
    {
        $best = [];
        $stepCount = count($steps);

        foreach ($events as $start => [$name, $enteredAt]) {
            if ($name !== $steps[0] || $enteredAt < $rangeStartMs || $enteredAt >= $rangeEndMs) {
                continue;
            }

            $path = [$enteredAt];
            $deadline = $enteredAt + $windowMs;

            for ($i = $start + 1, $total = count($events); $i < $total && count($path) < $stepCount; $i++) {
                [$nextName, $at] = $events[$i];

                if ($at > $deadline) {
                    break;
                }

                if ($nextName === $steps[count($path)]) {
                    $path[] = $at;
                }
            }

            if (count($path) > count($best)) {
                $best = $path;
            }

            if (count($best) === $stepCount) {
                break;
            }
        }

        return $best;
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return (int) round(count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2);
    }
}
