<?php

namespace Tests\Feature;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SimulateTrafficCommandTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = Game::factory()->create(['slug' => 'starfall-tactics']);
    }

    public function test_it_writes_live_traffic_directly(): void
    {
        $this->artisan('analytics:simulate', ['--iterations' => 1, '--sessions' => 3])
            ->expectsOutputToContain('(direct)')
            ->assertSuccessful();

        $this->assertGreaterThan(0, $this->game->events()->count());
        $this->assertGreaterThan(0, $this->game->players()->count());
    }

    public function test_it_sends_batches_through_the_ingestion_endpoint(): void
    {
        Http::fake(['*' => Http::response(['accepted' => 12, 'duplicates' => 0, 'new_players' => 1], 202)]);

        $this->artisan('analytics:simulate', ['--http' => 'http://ingest.test/', '--key' => 'gk_live', '--iterations' => 1])
            ->expectsOutputToContain('accepted 12')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'http://ingest.test/api/v1/events'
            && $request->hasHeader('Authorization', 'Bearer gk_live')
            && count($request['events']) > 0);
        $this->assertSame(0, $this->game->events()->count());
    }

    public function test_it_reports_a_rejected_key(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Invalid or missing ingest key.'], 401)]);

        $this->artisan('analytics:simulate', ['--http' => 'http://ingest.test', '--key' => 'gk_wrong', '--iterations' => 1])
            ->expectsOutputToContain('HTTP 401: Invalid or missing ingest key.')
            ->assertSuccessful();
    }

    public function test_http_mode_requires_a_key(): void
    {
        Http::fake();

        $this->artisan('analytics:simulate', ['--http' => 'http://ingest.test', '--iterations' => 1])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_an_unknown_game_fails(): void
    {
        $this->artisan('analytics:simulate', ['game' => 'no-such-game', '--iterations' => 1])->assertFailed();
    }
}
