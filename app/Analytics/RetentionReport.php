<?php

namespace App\Analytics;

use App\Models\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Classic N-day cohort retention: of the players first seen on day C, the share that were
 * active again exactly N days later. Read straight from the player_activity_days rollup.
 */
final class RetentionReport
{
    /**
     * @return array{
     *     range: array{from: string, to: string, days: int},
     *     max_day: int,
     *     cohorts: list<array{date: string, size: int, retained: list<int|null>, rates: list<float|null>}>,
     *     average: list<array{day: int, rate: float|null, cohorts: int}>
     * }
     */
    public function build(Game $game, DateRange $cohorts, int $maxDay, ?string $platform = null, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::today('UTC');

        $counts = DB::table('player_activity_days AS d')
            ->where('d.game_id', $game->id)
            ->whereBetween('d.cohort_on', [$cohorts->fromDate(), $cohorts->toDate()])
            ->where('d.day_index', '<=', $maxDay)
            ->when($platform, fn ($query) => $query
                ->join('players AS p', 'p.id', '=', 'd.player_id')
                ->where('p.platform', $platform))
            ->groupBy('d.cohort_on', 'd.day_index')
            ->selectRaw('d.cohort_on AS cohort_on, d.day_index AS day_index, COUNT(*) AS players')
            ->get();

        $matrix = [];
        foreach ($counts as $row) {
            $matrix[$row->cohort_on][(int) $row->day_index] = (int) $row->players;
        }

        $rows = [];
        $totals = array_fill(0, $maxDay + 1, ['retained' => 0, 'size' => 0, 'cohorts' => 0]);

        foreach ($cohorts->dates() as $date) {
            $size = $matrix[$date][0] ?? 0;
            $observableDays = min($maxDay, (int) CarbonImmutable::parse($date, 'UTC')->diffInDays($asOf, false));

            $retained = [];
            $rates = [];

            for ($day = 0; $day <= $maxDay; $day++) {
                // A day that hasn't happened yet is unknown, not zero - leave it out of every average.
                if ($day > $observableDays || $size === 0) {
                    $retained[] = $day > $observableDays ? null : 0;
                    $rates[] = null;

                    continue;
                }

                $count = $matrix[$date][$day] ?? 0;
                $retained[] = $count;
                $rates[] = round($count / $size, 4);

                $totals[$day]['retained'] += $count;
                $totals[$day]['size'] += $size;
                $totals[$day]['cohorts']++;
            }

            $rows[] = ['date' => $date, 'size' => $size, 'retained' => $retained, 'rates' => $rates];
        }

        $average = [];
        foreach ($totals as $day => $total) {
            $average[] = [
                'day' => $day,
                'rate' => $total['size'] > 0 ? round($total['retained'] / $total['size'], 4) : null,
                'cohorts' => $total['cohorts'],
            ];
        }

        return [
            'range' => $cohorts->toArray(),
            'max_day' => $maxDay,
            'cohorts' => $rows,
            'average' => $average,
        ];
    }
}
