<?php

namespace App\Analytics;

use App\Models\Game;
use Illuminate\Support\Facades\DB;

/**
 * Headline KPIs for a date range, each compared with the preceding period of equal length,
 * plus the daily series the overview charts are drawn from.
 */
final class OverviewReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(Game $game, DateRange $range): array
    {
        $current = $this->kpis($game, $range);
        $previous = $this->kpis($game, $range->previous());

        return [
            'range' => $range->toArray(),
            'previous_range' => $range->previous()->toArray(),
            'kpis' => array_map(
                fn (string $key) => [
                    'key' => $key,
                    'value' => $current[$key],
                    'previous' => $previous[$key],
                    'change' => $this->change($current[$key], $previous[$key]),
                ],
                array_keys($current),
            ),
            'series' => $this->series($game, $range),
            'platforms' => $this->breakdown($game, $range, 'platform'),
            'countries' => $this->breakdown($game, $range, 'country', 8),
        ];
    }

    /**
     * @return array<string, int|float|null>
     */
    private function kpis(Game $game, DateRange $range): array
    {
        $activity = DB::table('player_activity_days')
            ->where('game_id', $game->id)
            ->whereBetween('activity_on', [$range->fromDate(), $range->toDate()])
            ->selectRaw('COUNT(*) AS player_days, COUNT(DISTINCT player_id) AS active_players')
            ->first();

        $sessions = DB::table('game_sessions')
            ->where('game_id', $game->id)
            ->whereBetween('started_on', [$range->fromDate(), $range->toDate()])
            ->selectRaw('COUNT(*) AS sessions, AVG(duration_seconds) AS avg_duration')
            ->first();

        $revenue = DB::table('events')
            ->where('game_id', $game->id)
            ->where('name', 'purchase')
            ->whereBetween('occurred_on', [$range->fromDate(), $range->toDate()])
            ->selectRaw('COALESCE(SUM(value), 0) AS revenue, COUNT(DISTINCT player_id) AS payers')
            ->first();

        $newPlayers = DB::table('players')
            ->where('game_id', $game->id)
            ->whereBetween('first_seen_on', [$range->fromDate(), $range->toDate()])
            ->count();

        $playerDays = (int) $activity->player_days;
        $activePlayers = (int) $activity->active_players;
        $revenueTotal = round((float) $revenue->revenue, 2);
        $payers = (int) $revenue->payers;

        return [
            'active_players' => $activePlayers,
            'avg_dau' => round($playerDays / $range->days(), 1),
            'new_players' => $newPlayers,
            'stickiness' => $activePlayers > 0 ? round($playerDays / $range->days() / $activePlayers, 4) : null,
            'sessions' => (int) $sessions->sessions,
            'avg_session_seconds' => $sessions->avg_duration === null ? null : (int) round((float) $sessions->avg_duration),
            'revenue' => $revenueTotal,
            'paying_players' => $payers,
            'arpdau' => $playerDays > 0 ? round($revenueTotal / $playerDays, 4) : null,
            'arppu' => $payers > 0 ? round($revenueTotal / $payers, 2) : null,
        ];
    }

    /**
     * @return array<string, list<array{date: string, value: int|float}>>
     */
    private function series(Game $game, DateRange $range): array
    {
        $between = [$range->fromDate(), $range->toDate()];

        $dau = DB::table('player_activity_days')
            ->where('game_id', $game->id)
            ->whereBetween('activity_on', $between)
            ->groupBy('activity_on')
            ->selectRaw('activity_on, COUNT(*) AS total')
            ->pluck('total', 'activity_on')
            ->all();

        $newPlayers = DB::table('players')
            ->where('game_id', $game->id)
            ->whereBetween('first_seen_on', $between)
            ->groupBy('first_seen_on')
            ->selectRaw('first_seen_on, COUNT(*) AS total')
            ->pluck('total', 'first_seen_on')
            ->all();

        $sessions = DB::table('game_sessions')
            ->where('game_id', $game->id)
            ->whereBetween('started_on', $between)
            ->groupBy('started_on')
            ->selectRaw('started_on, COUNT(*) AS sessions, AVG(duration_seconds) AS avg_duration')
            ->get()
            ->keyBy('started_on');

        $revenue = DB::table('events')
            ->where('game_id', $game->id)
            ->where('name', 'purchase')
            ->whereBetween('occurred_on', $between)
            ->groupBy('occurred_on')
            ->selectRaw('occurred_on, SUM(value) AS total')
            ->pluck('total', 'occurred_on')
            ->all();

        return [
            'dau' => $range->fill(array_map('intval', $dau)),
            'new_players' => $range->fill(array_map('intval', $newPlayers)),
            'sessions' => $range->fill($sessions->map(fn ($row) => (int) $row->sessions)->all()),
            'avg_session_seconds' => $range->fill($sessions->map(fn ($row) => (int) round((float) $row->avg_duration))->all()),
            'revenue' => $range->fill(array_map(fn ($value) => round((float) $value, 2), $revenue)),
        ];
    }

    /**
     * Active players in the range split by a player attribute.
     *
     * @return list<array{label: string, players: int, share: float}>
     */
    private function breakdown(Game $game, DateRange $range, string $column, int $limit = 6): array
    {
        $rows = DB::table('player_activity_days AS d')
            ->join('players AS p', 'p.id', '=', 'd.player_id')
            ->where('d.game_id', $game->id)
            ->whereBetween('d.activity_on', [$range->fromDate(), $range->toDate()])
            ->groupBy("p.{$column}")
            ->selectRaw("p.{$column} AS label, COUNT(DISTINCT d.player_id) AS players")
            ->orderByDesc('players')
            ->orderBy('label')
            ->get();

        $total = max(1, $rows->sum('players'));
        $top = $rows->take($limit);
        $rest = $rows->slice($limit)->sum('players');

        $result = $top->map(fn ($row) => [
            'label' => $row->label ?? 'unknown',
            'players' => (int) $row->players,
            'share' => round($row->players / $total, 4),
        ])->values()->all();

        if ($rest > 0) {
            $result[] = ['label' => 'Other', 'players' => (int) $rest, 'share' => round($rest / $total, 4)];
        }

        return $result;
    }

    private function change(int|float|null $current, int|float|null $previous): ?float
    {
        if ($current === null || $previous === null || (float) $previous === 0.0) {
            return null;
        }

        return round(($current - $previous) / abs($previous), 4);
    }
}
