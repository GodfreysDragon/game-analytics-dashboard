<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SendsEvents;
use Tests\TestCase;

class EventIngestionTest extends TestCase
{
    use RefreshDatabase;
    use SendsEvents;

    private const KEY = 'gk_test_key_for_ingestion';

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 12:00:00');
        $this->game = Game::factory()->withKey(self::KEY)->create();
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function send(array $events, string $key = self::KEY): TestResponse
    {
        return $this->withToken($key)->postJson('/api/v1/events', ['events' => $events]);
    }

    public function test_it_rejects_requests_without_a_valid_ingest_key(): void
    {
        $events = [$this->event('session_start', 'p1', '2026-06-15 11:00:00')];

        $this->postJson('/api/v1/events', ['events' => $events])->assertUnauthorized();
        $this->send($events, 'gk_wrong')->assertUnauthorized();
        $this->send($events, 'not-even-a-key')->assertUnauthorized();

        $this->assertDatabaseCount('events', 0);
    }

    public function test_the_key_can_also_be_sent_in_the_x_game_key_header(): void
    {
        $this->withHeader('X-Game-Key', self::KEY)
            ->postJson('/api/v1/events', ['events' => [$this->event('session_start', 'p1', '2026-06-15 11:00:00')]])
            ->assertAccepted()
            ->assertJson(['accepted' => 1]);
    }

    public function test_a_batch_creates_events_players_sessions_and_activity_days(): void
    {
        $this->send([
            $this->event('session_start', 'alice', '2026-06-15 10:00:00', ['session_id' => 's1', 'platform' => 'ios', 'country' => 'gb', 'app_version' => '1.0.0']),
            $this->event('purchase', 'alice', '2026-06-15 10:05:00', ['session_id' => 's1', 'value' => 4.99, 'properties' => ['item' => 'gems']]),
            $this->event('session_end', 'alice', '2026-06-15 10:20:30', ['session_id' => 's1']),
            $this->event('session_start', 'bob', '2026-06-14 09:00:00', ['platform' => 'android']),
        ])->assertAccepted()->assertExactJson(['accepted' => 4, 'duplicates' => 0, 'new_players' => 2]);

        $alice = Player::where('external_id', 'alice')->sole();
        $this->assertSame('ios', $alice->platform);
        $this->assertSame('GB', $alice->country);
        $this->assertSame(3, $alice->event_count);
        $this->assertSame(1, $alice->session_count);
        $this->assertSame(4.99, $alice->lifetime_value);
        $this->assertSame('2026-06-15 10:00:00', $alice->first_seen_at->toDateTimeString());
        $this->assertSame('2026-06-15 10:20:30', $alice->last_seen_at->toDateTimeString());

        $this->assertDatabaseHas('game_sessions', ['session_key' => 's1', 'player_id' => $alice->id, 'duration_seconds' => 1230, 'event_count' => 3]);
        $this->assertDatabaseHas('events', ['name' => 'purchase', 'player_id' => $alice->id, 'occurred_on' => '2026-06-15']);
        $this->assertDatabaseHas('player_activity_days', ['player_id' => $alice->id, 'activity_on' => '2026-06-15', 'day_index' => 0]);
        $this->assertDatabaseCount('player_activity_days', 2);
    }

    public function test_resending_a_batch_is_idempotent(): void
    {
        $batch = [
            $this->event('session_start', 'alice', '2026-06-15 10:00:00', ['session_id' => 's1']),
            $this->event('level_start', 'alice', '2026-06-15 10:01:00', ['session_id' => 's1']),
        ];

        $this->send($batch)->assertAccepted()->assertJson(['accepted' => 2, 'duplicates' => 0]);
        $this->send($batch)->assertAccepted()->assertExactJson(['accepted' => 0, 'duplicates' => 2, 'new_players' => 0]);

        $this->assertDatabaseCount('events', 2);
        $this->assertSame(2, Player::sole()->event_count);
        $this->assertDatabaseHas('game_sessions', ['session_key' => 's1', 'event_count' => 2]);
    }

    public function test_a_retry_that_commits_while_waiting_for_the_player_locks_is_not_counted_twice(): void
    {
        $batch = [
            $this->event('session_start', 'alice', '2026-06-15 10:00:00', ['session_id' => 's1']),
            $this->event('purchase', 'alice', '2026-06-15 10:01:00', ['session_id' => 's1', 'value' => 4.99]),
        ];

        // Stand in for a concurrent retry: the same batch commits just as this request takes its player locks.
        $retried = false;
        DB::listen(function (QueryExecuted $query) use ($batch, &$retried): void {
            if (! $retried && str_starts_with($query->sql, 'select') && str_contains($query->sql, '"players"')) {
                $retried = true;
                $this->ingest($this->game, $batch);
            }
        });

        $this->send($batch)->assertAccepted()->assertJson(['accepted' => 0, 'duplicates' => 2]);

        $alice = Player::sole();
        $this->assertSame(2, $alice->event_count);
        $this->assertSame(4.99, $alice->lifetime_value);
        $this->assertSame(1, $alice->session_count);
        $this->assertDatabaseHas('game_sessions', ['session_key' => 's1', 'event_count' => 2]);
    }

    public function test_a_uuid_repeated_inside_one_batch_is_stored_once(): void
    {
        $event = $this->event('session_start', 'alice', '2026-06-15 10:00:00');

        $this->send([$event, $event])->assertAccepted()->assertJson(['accepted' => 1, 'duplicates' => 1]);

        $this->assertDatabaseCount('events', 1);
    }

    public function test_the_same_uuid_may_be_used_by_different_games(): void
    {
        $other = Game::factory()->withKey('gk_other_game')->create();
        $event = $this->event('session_start', 'alice', '2026-06-15 10:00:00');

        $this->send([$event])->assertJson(['accepted' => 1]);
        $this->send([$event], 'gk_other_game')->assertJson(['accepted' => 1]);

        $this->assertSame(1, $other->events()->count());
    }

    public function test_a_late_event_moves_the_player_into_the_earlier_cohort(): void
    {
        $this->send([
            $this->event('session_start', 'alice', '2026-06-10 10:00:00'),
            $this->event('session_start', 'alice', '2026-06-12 10:00:00'),
        ]);

        // Buffered offline play from two days earlier arrives later.
        $this->send([$this->event('session_start', 'alice', '2026-06-08 23:00:00')]);

        $alice = Player::sole();
        $this->assertSame('2026-06-08', $alice->first_seen_on->toDateString());

        $days = DB::table('player_activity_days')->orderBy('activity_on')->get(['activity_on', 'cohort_on', 'day_index']);
        $this->assertEquals([
            ['2026-06-08', '2026-06-08', 0],
            ['2026-06-10', '2026-06-08', 2],
            ['2026-06-12', '2026-06-08', 4],
        ], $days->map(fn ($row) => [$row->activity_on, $row->cohort_on, (int) $row->day_index])->all());
    }

    public function test_sessions_split_across_batches_are_merged(): void
    {
        $this->send([$this->event('session_start', 'alice', '2026-06-15 10:00:00', ['session_id' => 's1'])]);
        $this->send([$this->event('session_end', 'alice', '2026-06-15 10:10:00', ['session_id' => 's1'])]);

        $this->assertDatabaseCount('game_sessions', 1);
        $this->assertDatabaseHas('game_sessions', ['session_key' => 's1', 'duration_seconds' => 600, 'event_count' => 2]);
        $this->assertSame(1, Player::sole()->session_count);
    }

    public function test_session_ids_are_scoped_to_the_player(): void
    {
        $this->send([
            $this->event('session_start', 'alice', '2026-06-15 10:00:00', ['session_id' => 'sess-1']),
            $this->event('session_start', 'bob', '2026-06-15 11:00:00', ['session_id' => 'sess-1']),
            $this->event('session_end', 'bob', '2026-06-15 11:05:00', ['session_id' => 'sess-1']),
        ])->assertAccepted();
        $this->send([$this->event('session_end', 'alice', '2026-06-15 10:10:00', ['session_id' => 'sess-1'])])->assertAccepted();

        $alice = Player::where('external_id', 'alice')->sole();
        $bob = Player::where('external_id', 'bob')->sole();

        $this->assertDatabaseCount('game_sessions', 2);
        $this->assertDatabaseHas('game_sessions', ['player_id' => $alice->id, 'session_key' => 'sess-1', 'duration_seconds' => 600, 'event_count' => 2]);
        $this->assertDatabaseHas('game_sessions', ['player_id' => $bob->id, 'session_key' => 'sess-1', 'duration_seconds' => 300, 'event_count' => 2]);
        $this->assertSame(1, $alice->session_count);
        $this->assertSame(1, $bob->session_count);
    }

    public function test_device_context_comes_from_the_newest_event(): void
    {
        $this->send([
            $this->event('session_start', 'alice', '2026-06-15 10:00:00', ['app_version' => '1.1.0']),
            $this->event('session_start', 'alice', '2026-06-14 10:00:00', ['app_version' => '1.0.0']),
        ]);

        $this->assertSame('1.1.0', Player::sole()->app_version);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidEvents(): array
    {
        return [
            'name not snake_case' => [['name' => 'Level Complete'], 'events.0.name'],
            'missing player' => [['player_id' => null], 'events.0.player_id'],
            'bad uuid' => [['uuid' => 'abc'], 'events.0.uuid'],
            'older than the ingestion window' => [['timestamp' => '2026-04-01T00:00:00Z'], 'events.0.timestamp'],
            'too far in the future' => [['timestamp' => '2026-06-15T13:00:00Z'], 'events.0.timestamp'],
            'unknown field' => [['user' => 'x'], 'events.0'],
            'unknown platform' => [['platform' => 'toaster'], 'events.0.platform'],
            'oversized properties' => [['properties' => ['blob' => str_repeat('x', 5000)]], 'events.0.properties'],
            'value not numeric' => [['value' => 'free'], 'events.0.value'],
        ];
    }

    /**
     * @param  array<string, mixed>  $override
     */
    #[DataProvider('invalidEvents')]
    public function test_invalid_events_reject_the_whole_batch(array $override, string $errorKey): void
    {
        $this->send([
            $this->event('session_start', 'alice', '2026-06-15 10:00:00'),
            [...$this->event('session_start', 'alice', '2026-06-15 10:00:00'), ...$override],
        ])->assertUnprocessable()->assertJsonValidationErrors(str_replace('events.0', 'events.1', $errorKey));

        $this->assertDatabaseCount('events', 0);
    }

    public function test_batches_are_capped_at_500_events(): void
    {
        $events = array_map(fn () => $this->event('tick', 'alice', '2026-06-15 10:00:00'), range(1, 501));

        $this->send($events)->assertUnprocessable()->assertJsonValidationErrors('events');
    }

    public function test_ingestion_is_rate_limited_per_game(): void
    {
        config(['analytics.ingest_requests_per_minute' => 2]);
        $other = Game::factory()->withKey('gk_other_game')->create();

        $this->send([$this->event('a', 'p', '2026-06-15 10:00:00')])->assertAccepted();
        $this->send([$this->event('a', 'p', '2026-06-15 10:00:00')])->assertAccepted();
        $this->send([$this->event('a', 'p', '2026-06-15 10:00:00')])->assertTooManyRequests();

        $this->send([$this->event('a', 'p', '2026-06-15 10:00:00')], 'gk_other_game')->assertAccepted();
        $this->assertSame(1, $other->events()->count());
    }

    public function test_rotating_the_key_revokes_the_old_one(): void
    {
        $newKey = $this->game->rotateIngestKey();

        $this->assertStringStartsWith(Game::KEY_PREFIX, $newKey);
        $this->assertNotSame(self::KEY, $newKey);
        $this->send([$this->event('a', 'p', '2026-06-15 10:00:00')])->assertUnauthorized();
        $this->send([$this->event('a', 'p', '2026-06-15 10:00:00', ['uuid' => (string) Str::uuid()])], $newKey)->assertAccepted();
    }
}
