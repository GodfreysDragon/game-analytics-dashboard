<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\SendsEvents;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;
    use SendsEvents;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-06-15 12:00:00');
        $this->game = Game::factory()->create(['name' => 'Starfall']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function protectedEndpoints(): array
    {
        return [
            'games' => ['/api/admin/games'],
            'overview' => ['/api/admin/games/{game}/overview'],
            'events report' => ['/api/admin/games/{game}/events/report'],
            'event stream' => ['/api/admin/games/{game}/events'],
            'retention' => ['/api/admin/games/{game}/retention'],
            'funnels' => ['/api/admin/games/{game}/funnels'],
            'players' => ['/api/admin/games/{game}/players'],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_dashboard_endpoints_require_a_signed_in_admin(string $uri): void
    {
        $this->getJson(str_replace('{game}', (string) $this->game->id, $uri))->assertUnauthorized();
    }

    public function test_an_admin_can_sign_in_and_out(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com', 'password' => 'secret-password']);

        $this->postJson('/api/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
        $this->assertGuest();

        $this->postJson('/api/admin/login', ['email' => 'admin@example.com', 'password' => 'secret-password'])
            ->assertOk()
            ->assertJsonPath('user.email', 'admin@example.com');
        $this->assertAuthenticatedAs($user);

        $this->getJson('/api/admin/me')->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/admin/logout')->assertOk();
        $this->assertGuest();
    }

    public function test_creating_a_game_returns_its_ingest_key_once(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson('/api/admin/games', ['name' => 'Pocket Harvest'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'pocket-harvest')
            ->assertJsonMissingPath('data.ingest_key_hash');

        $key = $response->json('ingest_key');
        $this->assertStringStartsWith('gk_', $key);
        $this->assertSame($response->json('data.id'), Game::findByIngestKey($key)?->id);

        $this->getJson('/api/admin/games')
            ->assertOk()
            ->assertJsonMissingPath('ingest_key')
            ->assertJsonCount(2, 'data');
    }

    public function test_reports_return_data_for_the_requested_range(): void
    {
        $this->ingest($this->game, [
            $this->event('session_start', 'alice', '2026-06-14 10:00'),
            $this->event('level_start', 'alice', '2026-06-14 10:01'),
        ]);
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->getJson("/api/admin/games/{$this->game->id}/overview?from=2026-06-01&to=2026-06-14")
            ->assertOk()
            ->assertJsonPath('range', ['from' => '2026-06-01', 'to' => '2026-06-14', 'days' => 14])
            ->assertJsonCount(14, 'series.dau')
            ->assertJsonPath('series.dau.13', ['date' => '2026-06-14', 'value' => 1]);

        $this->getJson("/api/admin/games/{$this->game->id}/events/report?from=2026-06-14&to=2026-06-14&names[]=level_start")
            ->assertOk()
            ->assertJsonPath('events.0.total', 1)
            ->assertJsonPath('trends.0.name', 'level_start')
            ->assertJsonCount(1, 'trends');

        $this->getJson("/api/admin/games/{$this->game->id}/retention?from=2026-06-14&to=2026-06-14&max_day=7")
            ->assertOk()
            ->assertJsonPath('cohorts.0.size', 1)
            ->assertJsonCount(8, 'cohorts.0.rates');

        $this->getJson("/api/admin/games/{$this->game->id}/events/catalog")
            ->assertOk()
            ->assertExactJson(['names' => ['level_start', 'session_start']]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidReportQueries(): array
    {
        return [
            'range over a year' => ['from=2025-01-01&to=2026-06-01', 'from'],
            'end before start' => ['from=2026-06-10&to=2026-06-01', 'to'],
            'bad date format' => ['from=06/01/2026', 'from'],
            'unknown platform' => ['platform=fridge', 'platform'],
        ];
    }

    #[DataProvider('invalidReportQueries')]
    public function test_report_filters_are_validated(string $query, string $error): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson("/api/admin/games/{$this->game->id}/overview?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors($error);
    }

    public function test_funnels_can_be_saved_reported_and_deleted(): void
    {
        $this->ingest($this->game, [
            $this->event('first_open', 'alice', '2026-06-14 10:00'),
            $this->event('tutorial_complete', 'alice', '2026-06-14 10:03'),
        ]);
        $this->actingAs(User::factory()->create());

        $id = $this->postJson("/api/admin/games/{$this->game->id}/funnels", [
            'name' => 'Onboarding',
            'steps' => ['first_open', 'tutorial_complete'],
            'window_minutes' => 30,
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/admin/games/{$this->game->id}/funnels/{$id}/report?from=2026-06-14&to=2026-06-14")
            ->assertOk()
            ->assertJsonPath('funnel.name', 'Onboarding')
            ->assertJsonPath('steps.1.players', 1)
            ->assertJsonPath('steps.1.median_seconds', 180);

        $this->putJson("/api/admin/games/{$this->game->id}/funnels/{$id}", ['name' => 'Renamed', 'steps' => ['first_open', 'purchase'], 'window_minutes' => 30])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');

        $this->deleteJson("/api/admin/games/{$this->game->id}/funnels/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('funnels', ['id' => $id]);
    }

    public function test_an_unsaved_funnel_can_be_previewed(): void
    {
        $this->ingest($this->game, [$this->event('first_open', 'alice', '2026-06-14 10:00')]);

        $this->actingAs(User::factory()->create())
            ->getJson("/api/admin/games/{$this->game->id}/funnels/preview?".http_build_query([
                'steps' => ['first_open', 'tutorial_complete'],
                'window_minutes' => 60,
                'from' => '2026-06-14',
                'to' => '2026-06-14',
            ]))
            ->assertOk()
            ->assertJsonPath('steps.0.players', 1)
            ->assertJsonPath('steps.1.players', 0);
    }

    public function test_funnel_definitions_are_validated(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson("/api/admin/games/{$this->game->id}/funnels", ['name' => '', 'steps' => ['only_one'], 'window_minutes' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'steps', 'window_minutes']);
    }

    public function test_funnels_and_players_cannot_be_reached_through_another_game(): void
    {
        $other = Game::factory()->create();
        $funnel = $other->funnels()->create(['name' => 'Theirs', 'steps' => ['a', 'b'], 'window_minutes' => 10]);
        $this->ingest($other, [$this->event('session_start', 'theirs', '2026-06-14 10:00')]);
        $player = Player::sole();

        $this->actingAs(User::factory()->create());

        $this->getJson("/api/admin/games/{$this->game->id}/funnels/{$funnel->id}/report")->assertNotFound();
        $this->deleteJson("/api/admin/games/{$this->game->id}/funnels/{$funnel->id}")->assertNotFound();
        $this->getJson("/api/admin/games/{$this->game->id}/players/{$player->id}")->assertNotFound();
        $this->getJson("/api/admin/games/{$this->game->id}/players/{$player->id}/events")->assertNotFound();
        $this->assertModelExists($funnel);
    }

    public function test_players_can_be_searched_and_inspected(): void
    {
        $this->ingest($this->game, [
            $this->event('session_start', 'whale_01', '2026-06-14 10:00', ['session_id' => 's1', 'platform' => 'ios']),
            $this->event('purchase', 'whale_01', '2026-06-14 10:05', ['session_id' => 's1', 'value' => 19.99, 'platform' => 'ios']),
            $this->event('session_start', 'minnow_01', '2026-06-14 11:00', ['platform' => 'android']),
            $this->event('session_start', 'wh%ale', '2026-06-14 11:00'),
        ]);
        $this->actingAs(User::factory()->create());

        $this->getJson("/api/admin/games/{$this->game->id}/players?q=wha")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'whale_01');

        $this->getJson("/api/admin/games/{$this->game->id}/players?q=wh%25")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'wh%ale');

        $this->getJson("/api/admin/games/{$this->game->id}/players?payers=1")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lifetime_value', 19.99);

        $whale = Player::where('external_id', 'whale_01')->sole();

        $this->getJson("/api/admin/games/{$this->game->id}/players/{$whale->id}")
            ->assertOk()
            ->assertJsonPath('player.session_count', 1)
            ->assertJsonPath('active_days', ['2026-06-14'])
            ->assertJsonPath('sessions.0.duration_seconds', 300)
            ->assertJsonCount(2, 'event_mix');

        $this->getJson("/api/admin/games/{$this->game->id}/players/{$whale->id}/events")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'purchase')
            ->assertJsonPath('data.0.value', 19.99);
    }

    public function test_the_event_stream_returns_only_newer_events_after_a_given_id(): void
    {
        $this->ingest($this->game, [
            $this->event('session_start', 'alice', '2026-06-14 10:00'),
            $this->event('level_start', 'alice', '2026-06-14 10:01'),
        ]);
        $this->actingAs(User::factory()->create());

        $latest = $this->getJson("/api/admin/games/{$this->game->id}/events")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.player.external_id', 'alice')
            ->json('data.0.id');

        $this->getJson("/api/admin/games/{$this->game->id}/events?after_id={$latest}")->assertJsonCount(0, 'data');

        $this->ingest($this->game, [$this->event('level_complete', 'alice', '2026-06-14 10:05')]);

        $this->getJson("/api/admin/games/{$this->game->id}/events?after_id={$latest}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'level_complete');
    }

    public function test_rotating_a_key_from_the_dashboard_returns_a_working_key(): void
    {
        $key = $this->actingAs(User::factory()->create())
            ->postJson("/api/admin/games/{$this->game->id}/rotate-key")
            ->assertOk()
            ->json('ingest_key');

        $this->withToken($key)
            ->postJson('/api/v1/events', ['events' => [$this->event('session_start', 'p', '2026-06-15 11:00')]])
            ->assertAccepted();
    }
}
