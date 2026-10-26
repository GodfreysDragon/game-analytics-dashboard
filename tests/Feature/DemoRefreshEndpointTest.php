<?php

namespace Tests\Feature;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DemoRefreshEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'refresh-token-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config(['analytics.demo_mode' => true, 'analytics.demo_days' => 1, 'analytics.demo_refresh_token' => self::TOKEN]);
        $this->travelTo('2026-06-15 19:00:00');
    }

    public function test_a_scheduler_with_the_token_can_refresh_the_demo(): void
    {
        $this->withToken(self::TOKEN)->postJson('/api/internal/demo-refresh')
            ->assertOk()
            ->assertJsonPath('output', fn (string $output) => str_contains($output, 'Starfall Tactics'));

        $this->assertSame(2, Game::count());
    }

    public function test_each_call_spends_a_limited_time_on_a_rebuild(): void
    {
        config(['analytics.demo_build_seconds' => 0]);

        $this->withToken(self::TOKEN)->postJson('/api/internal/demo-refresh')
            ->assertOk()
            ->assertJsonPath('output', fn (string $output) => str_contains($output, 'continuing on the next run'));

        $this->assertTrue(Game::where('building', true)->where('name', 'Starfall Tactics')->exists());
    }

    public function test_it_is_hidden_without_the_right_token(): void
    {
        $this->postJson('/api/internal/demo-refresh')->assertNotFound();
        $this->withToken('wrong')->postJson('/api/internal/demo-refresh')->assertNotFound();

        $this->assertSame(0, Game::count());
    }

    public function test_it_is_hidden_outside_demo_mode_or_without_a_configured_token(): void
    {
        config(['analytics.demo_mode' => false]);
        $this->withToken(self::TOKEN)->postJson('/api/internal/demo-refresh')->assertNotFound();

        config(['analytics.demo_mode' => true, 'analytics.demo_refresh_token' => null]);
        $this->withToken('')->postJson('/api/internal/demo-refresh')->assertNotFound();

        $this->assertSame(0, Game::count());
    }

    public function test_overlapping_calls_are_refused(): void
    {
        $lock = Cache::lock('analytics:demo-refresh', 60);
        $lock->get();

        $this->withToken(self::TOKEN)->postJson('/api/internal/demo-refresh')->assertConflict();

        $lock->release();
        $this->assertSame(0, Game::count());
    }
}
