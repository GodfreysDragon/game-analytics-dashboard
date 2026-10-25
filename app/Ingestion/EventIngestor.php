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
            $fresh = $this->withoutStoredEvents($game, $batch);

            if ($fresh === []) {
                return new IngestResult(0, $inBatchDuplicates + count($batch), 0);
            }

            [$players, $newPlayers] = $this->lockPlayers($game, $fresh);

            // A concurrent retry of this batch may have committed while we waited for the player
            // locks. Check again now that we hold them, so its events are never counted twice. This
            // read takes a shared lock so MySQL reads the latest rows, not the transaction's snapshot.
            $fresh = $this->withoutStoredEvents($game, $fresh, locking: true);

            if ($fresh === []) {
                return new IngestResult(0, $inBatchDuplicates + count($batch), $newPlayers);
            }

            $movedCohorts = $this->updatePlayers($fresh, $players);

            $this->insertEvents($game, $fresh, $players, $receivedAt);
            $this->recordActivityDays($game, $fresh, $players, $movedCohorts);
            $this->upsertSessions($game, $fresh, $players);

            return new IngestResult(count($fresh), $inBatchDuplicates + count($batch) - count($fresh), $newPlayers);
        }, 3);
    }

    /**
     * Drop events whose uuid this game has already stored.
     *
     * @param  array<string, array>  $events  keyed by uuid
     * @return array<string, array>
     */
    private function withoutStoredEvents(Game $game, array $events, bool $locking = false): array
    {
        $stored = DB::table('events')
            ->where('game_id', $game->id)
            ->whereIn('uuid', array_map('strval', array_keys($events)))
            ->when($locking, fn ($query) => $query->sharedLock())
            ->pluck('uuid')
            ->all();

        return array_diff_key($events, array_flip($stored));
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
     * Create unseen players, then lock every player in the batch. The locks serialise
     * concurrent batches for the same players, which keeps the counter updates exact.
     *
     * Returns the player rows keyed by external id and how many players were created.
     *
     * @return array{0: array<string, object>, 1: int}
     */
    private function lockPlayers(Game $game, array $events): array
    {
        $stats = $this->playerStats($events);
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

        return [$rows->all(), $created];
    }

    /**
     * Widen first/last-seen and add the batch to each player's counters.
     *
     * Returns player id => new cohort date for players whose first-seen day moved earlier
     * because a late event arrived.
     *
     * @param  array<string, object>  $players  locked rows keyed by external id; first_seen_on is updated in place
     * @return array<int, string>
     */
    private function updatePlayers(array $events, array $players): array
    {
        $now = now()->format(self::DATETIME);
        $movedCohorts = [];

        foreach ($this->playerStats($events) as $externalId => $s) {
            $row = $players[$externalId];
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

        return $movedCohorts;
    }

    /**
     * Per-player totals for a batch: first/last event time, event count, spend, and the
     * device context of the newest event that carries one.
     *
     * @return array<string, array{first: CarbonImmutable, last: CarbonImmutable, count: int, value: float, context: array<string, string>, context_at: CarbonImmutable|null}>
     */
    private function playerStats(array $events): array
    {
        $stats = [];

        foreach ($events as $event) {
            $id = $event['player'];
            $s = $stats[$id] ?? ['first' => $event['at'], 'last' => $event['at'], 'count' => 0, 'value' => 0.0, 'context' => [], 'context_at' => null];

            $s['first'] = $event['at']->min($s['first']);
            $s['last'] = $event['at']->max($s['last']);
            $s['count']++;
            $s['value'] += $event['name'] === 'purchase' ? (float) $event['value'] : 0.0;

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

        return $stats;
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

            // Session ids only need to be unique per player, so two players' "sess-1" stay apart.
            $playerId = $players[$e['player']]->id;
            $key = $playerId.'|'.$e['session'];
            $s = $sessions[$key] ?? ['player_id' => $playerId, 'session_key' => $e['session'], 'start' => $e['at'], 'end' => $e['at'], 'count' => 0];
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
            ->whereIn('player_id', array_unique(array_column($sessions, 'player_id')))
            ->whereIn('session_key', array_unique(array_column($sessions, 'session_key')))
            ->lockForUpdate()
            ->get(['id', 'player_id', 'session_key', 'started_at', 'ended_at'])
            ->keyBy(fn (object $row) => $row->player_id.'|'.$row->session_key);

        $inserts = [];
        $newSessionsPerPlayer = [];

        foreach ($sessions as $key => $s) {
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
                'session_key' => $s['session_key'],
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
