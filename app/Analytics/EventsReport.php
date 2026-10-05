<?php

namespace App\Analytics;

use App\Models\Game;
use Illuminate\Support\Facades\DB;

/**
 * Event volume by name: a ranked table for the range and daily trends for a few chosen names.
 */
final class EventsReport
{
    public const MAX_TRENDS = 4;

    /**
     * @param  list<string>  $trendNames  names to chart; defaults to the most frequent ones
     * @return array<string, mixed>
     */
    public function build(Game $game, DateRange $range, array $trendNames = []): array
    {
        $between = [$range->fromDate(), $range->toDate()];

        $top = DB::table('events')
            ->where('game_id', $game->id)
            ->whereBetween('occurred_on', $between)
            ->groupBy('name')
            ->selectRaw('name, COUNT(*) AS total, COUNT(DISTINCT player_id) AS players')
            ->orderByDesc('total')
            ->orderBy('name')
            ->get();

        $grandTotal = max(1, $top->sum('total'));
        $activePlayers = max(1, (int) DB::table('player_activity_days')
            ->where('game_id', $game->id)
            ->whereBetween('activity_on', $between)
            ->distinct()
            ->count('player_id'));

        $trendNames = array_slice($trendNames ?: $top->take(self::MAX_TRENDS)->pluck('name')->all(), 0, self::MAX_TRENDS);

        $daily = DB::table('events')
            ->where('game_id', $game->id)
            ->whereBetween('occurred_on', $between)
            ->whereIn('name', $trendNames)
            ->groupBy('name', 'occurred_on')
            ->selectRaw('name, occurred_on, COUNT(*) AS total')
            ->get()
            ->groupBy('name');

        return [
            'range' => $range->toArray(),
            'events' => $top->map(fn ($row) => [
                'name' => $row->name,
                'total' => (int) $row->total,
                'players' => (int) $row->players,
                'share' => round($row->total / $grandTotal, 4),
                'per_player' => round($row->total / max(1, $row->players), 2),
                'reach' => round($row->players / $activePlayers, 4),
            ])->values()->all(),
            'trends' => array_map(fn (string $name) => [
                'name' => $name,
                'points' => $range->fill(
                    ($daily->get($name) ?? collect())->mapWithKeys(fn ($row) => [$row->occurred_on => (int) $row->total])->all(),
                ),
            ], $trendNames),
        ];
    }

    /**
     * Every event name the game has ever sent, for pickers.
     *
     * @return list<string>
     */
    public function catalog(Game $game): array
    {
        return DB::table('events')
            ->where('game_id', $game->id)
            ->distinct()
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
