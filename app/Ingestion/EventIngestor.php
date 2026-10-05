<?php

namespace App\Ingestion;

use App\Models\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Writes a batch of raw events and keeps the rollup tables (players, player_activity_days,
 * game_sessions) in step inside one transaction, so dashboards never see a half-applied batch.
 *
 * Expected event shape (already validated): uuid, name, player_id, timestamp, and optional
 * session_id, value, properties, platform, country, app_version.
 */
final class EventIngestor
{
    private const DATETIME = 'Y-m-d H:i:s.v';

    private const INSERT_CHUNK = 250;

    public function ingest(Game $game, array $events, ?CarbonImmutable $receivedAt = null): IngestResult
    {
        $receivedAt ??= CarbonImmutable::now('UTC');

        $batch = $this->normalise($events);
        $inBatchDuplicates = count($events) - count($batch);

        if ($batch === []) {
            return new IngestResult(0, $inBatchDuplicates, 0);
        }

        return DB::transaction(function () use ($game, $batch, $inBatchDuplicates, $receivedAt) {
            $seen = DB::table('events')
                ->where('game_id', $game->id)
                ->whereIn('uuid', array_map('strval', array_keys($batch)))
                ->pluck('uuid')
                ->all();

            $fresh = array_diff_key($batch, array_flip($seen));

            if ($fresh === []) {
                return new IngestResult(0, $inBatchDuplicates + count($seen), 0);
            }

            [$players, $newPlayers, $movedCohorts] = $this->upsertPlayers($game, $fresh);

            $this->insertEvents($game, $fresh, $players, $receivedAt);
            $this->recordActivityDays($game, $fresh, $players, $movedCohorts);
            $this->upsertSessions($game, $fresh, $players);

            return new IngestResult(count($fresh), $inBatchDuplicates + count($seen), $newPlayers);
        });
    }

    /**
     * Parse timestamps to UTC and drop repeated uuids within the batch (first one wins).
     *
     * @return array<string, array> keyed by lower-cased uuid
     */
    private function normalise(array $events): array
    {
        $batch = [];

        foreach ($events as $event) {
            $uuid = strtolower($event['uuid']);

            if (isset($batch[$uuid])) {
                continue;
            }

            $batch[$uuid] = [
                'uuid' => $uuid,
                'name' => $event['name'],
                'player' => (string) $event['player_id'],
                'session' => isset($event['session_id']) ? (string) $event['session_id'] : null,
                'value' => isset($event['value']) ? (float) $event['value'] : null,
                'properties' => $event['properties'] ?? null,
                'platform' => $event['platform'] ?? null,
                'country' => isset($event['country']) ? strtoupper($event['country']) : null,
                'app_version' => $event['app_version'] ?? null,
                'at' => CarbonImmutable::parse($event['timestamp'])->utc(),
            ];
        }

        return $batch;
    }

    /**
     * Create unseen players and widen first/last-seen for known ones.
     *
     * Returns the player rows keyed by external id, how many players were created, and
     * player id => new cohort date for players whose first-seen day moved earlier because
     * a late event arrived.
     *
     * @return array{0: array<string, object>, 1: int, 2: array<int, string>}
     */
    private function upsertPlayers(Game $game, array $events): array
    {
        $stats = [];

        foreach ($events as $event) {
            $id = $event['player'];
            $s = $stats[$id] ?? ['first' => $event['at'], 'last' => $event['at'], 'count' => 0, 'value' => 0.0, 'context' => [], 'context_at' => null];

            $s['first'] = $event['at']->min($s['first']);
            $s['last'] = $event['at']->max($s['last']);
            $s['count']++;
            $s['value'] += $event['name'] === 'purchase' ? (float) $event['value'] : 0.0;

            // Device context comes from the newest event that carries it.
            $context = array_filter([
                'platform' => $event['platform'],
                'country' => $event['country'],
                'app_version' => $event['app_version'],
            ]);
            if ($context !== [] && ($s['context_at'] === null || $event['at']->gte($s['context_at']))) {
                $s['context'] = $context + $s['context'];
                $s['context_at'] = $event['at'];
            }

            $stats[$id] = $s;
        }

        $externalIds = array_map('strval', array_keys($stats));
        $now = now()->format(self::DATETIME);

        $created = DB::table('players')->insertOrIgnore(array_map(fn (string $id) => [
            'game_id' => $game->id,
            'external_id' => $id,
            'first_seen_at' => $stats[$id]['first']->format(self::DATETIME),
            'first_seen_on' => $stats[$id]['first']->toDateString(),
            'last_seen_at' => $stats[$id]['last']->format(self::DATETIME),
            'created_at' => $now,
            'updated_at' => $now,
        ], $externalIds));

        $rows = DB::table('players')
            ->where('game_id', $game->id)
            ->whereIn('external_id', $externalIds)
            ->lockForUpdate()
            ->get(['id', 'external_id', 'first_seen_at', 'first_seen_on', 'last_seen_at'])
            ->keyBy('external_id');

        $movedCohorts = [];

        foreach ($rows as $externalId => $row) {
            $s = $stats[$externalId];
            $first = $s['first']->min(CarbonImmutable::parse($row->first_seen_at, 'UTC'));
            $last = $s['last']->max(CarbonImmutable::parse($row->last_seen_at, 'UTC'));

            if ($first->toDateString() !== $row->first_seen_on) {
                $movedCohorts[$row->id] = $first->toDateString();
            }

            DB::table('players')->where('id', $row->id)->update([
                'first_seen_at' => $first->format(self::DATETIME),
                'first_seen_on' => $first->toDateString(),
                'last_seen_at' => $last->format(self::DATETIME),
                'event_count' => DB::raw('event_count + '.(int) $s['count']),
                'lifetime_value' => DB::raw('lifetime_value + '.sprintf('%.4F', $s['value'])),
                'updated_at' => $now,
                ...$s['context'],
            ]);

            $row->first_seen_on = $first->toDateString();
        }

        return [$rows->all(), $created, $movedCohorts];
    }

    private function insertEvents(Game $game, array $events, array $players, CarbonImmutable $receivedAt): void
    {
        $received = $receivedAt->format(self::DATETIME);

        $rows = array_map(fn (array $e) => [
            'game_id' => $game->id,
            'player_id' => $players[$e['player']]->id,
            'uuid' => $e['uuid'],
            'name' => $e['name'],
            'session_key' => $e['session'],
            'value' => $e['value'],
            'properties' => $e['properties'] === null ? null : json_encode($e['properties']),
            'occurred_at' => $e['at']->format(self::DATETIME),
            'occurred_on' => $e['at']->toDateString(),
            'received_at' => $received,
        ], array_values($events));

        // insertOrIgnore guards the unique (game_id, uuid) index against a concurrent retry of the same batch.
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table('events')->insertOrIgnore($chunk);
        }
    }

    private function recordActivityDays(Game $game, array $events, array $players, array $movedCohorts): void
    {
        $rows = [];

        foreach ($events as $e) {
            $player = $players[$e['player']];
            $day = $e['at']->toDateString();

            $rows[$player->id.'|'.$day] ??= [
                'game_id' => $game->id,
                'player_id' => $player->id,
                'activity_on' => $day,
                'cohort_on' => $player->first_seen_on,
                'day_index' => self::daysBetween($player->first_seen_on, $day),
            ];
        }

        foreach (array_chunk(array_values($rows), self::INSERT_CHUNK) as $chunk) {
            DB::table('player_activity_days')->insertOrIgnore($chunk);
        }

        // A late event moved these players into an earlier cohort: re-index their existing days.
        foreach ($movedCohorts as $playerId => $cohort) {
            $days = DB::table('player_activity_days')
                ->where('player_id', $playerId)
                ->where('cohort_on', '!=', $cohort)
                ->pluck('activity_on');

            foreach ($days as $day) {
                DB::table('player_activity_days')
                    ->where('player_id', $playerId)
                    ->where('activity_on', $day)
                    ->update(['cohort_on' => $cohort, 'day_index' => self::daysBetween($cohort, $day)]);
            }
        }
    }

    private function upsertSessions(Game $game, array $events, array $players): void
    {
        $sessions = [];

        foreach ($events as $e) {
            if ($e['session'] === null) {
                continue;
            }

            $key = $e['session'];
            $s = $sessions[$key] ?? ['player_id' => $players[$e['player']]->id, 'start' => $e['at'], 'end' => $e['at'], 'count' => 0];
            $s['start'] = $e['at']->min($s['start']);
            $s['end'] = $e['at']->max($s['end']);
            $s['count']++;
            $sessions[$key] = $s;
        }

        if ($sessions === []) {
            return;
        }

        $existing = DB::table('game_sessions')
            ->where('game_id', $game->id)
            ->whereIn('session_key', array_map('strval', array_keys($sessions)))
            ->lockForUpdate()
            ->get(['id', 'session_key', 'started_at', 'ended_at'])
            ->keyBy('session_key');

        $inserts = [];
        $newSessionsPerPlayer = [];

        foreach ($sessions as $key => $s) {
            $key = (string) $key;

            if ($row = $existing->get($key)) {
                $start = $s['start']->min(CarbonImmutable::parse($row->started_at, 'UTC'));
                $end = $s['end']->max(CarbonImmutable::parse($row->ended_at, 'UTC'));

                DB::table('game_sessions')->where('id', $row->id)->update([
                    'started_at' => $start->format(self::DATETIME),
                    'started_on' => $start->toDateString(),
                    'ended_at' => $end->format(self::DATETIME),
                    'duration_seconds' => (int) $start->diffInSeconds($end, true),
                    'event_count' => DB::raw('event_count + '.(int) $s['count']),
                ]);

                continue;
            }

            $inserts[] = [
                'game_id' => $game->id,
                'player_id' => $s['player_id'],
                'session_key' => $key,
                'started_at' => $s['start']->format(self::DATETIME),
                'started_on' => $s['start']->toDateString(),
                'ended_at' => $s['end']->format(self::DATETIME),
                'duration_seconds' => (int) $s['start']->diffInSeconds($s['end'], true),
                'event_count' => $s['count'],
            ];
            $newSessionsPerPlayer[$s['player_id']] = ($newSessionsPerPlayer[$s['player_id']] ?? 0) + 1;
        }

        foreach (array_chunk($inserts, self::INSERT_CHUNK) as $chunk) {
            DB::table('game_sessions')->insertOrIgnore($chunk);
        }

        foreach ($newSessionsPerPlayer as $playerId => $count) {
            DB::table('players')->where('id', $playerId)->increment('session_count', $count);
        }
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int) CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC'), true);
    }
}
