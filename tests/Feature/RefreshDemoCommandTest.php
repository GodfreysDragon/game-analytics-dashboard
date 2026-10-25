<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RefreshDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['analytics.demo_mode' => true, 'analytics.demo_days' => 2]);
        // Evening UTC: the simulated players are busiest, so every window below holds events.
        $this->travelTo('2026-06-15 19:00:00');
    }

    public function test_it_only_runs_in_demo_mode(): void
    {
        config(['analytics.demo_mode' => false]);

        $this->artisan('analytics:demo-refresh')->assertFailed();

        $this->assertSame(0, Game::count());
    }

    public function test_it_builds_the_demo_games_on_the_first_run(): void
    {
        $this->artisan('analytics:demo-refresh')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['starfall-tactics', 'pocket-harvest'], Game::pluck('slug')->all());
        $this->assertSame(3, Game::firstWhere('slug', 'starfall-tactics')->funnels()->count());
        $this->assertGreaterThan(0, Event::count());
        $this->assertLessThanOrEqual('2026-06-15 19:00:00.000', Event::max('occurred_at'));
    }

    public function test_later_runs_add_only_new_events_and_match_a_fresh_build(): void
    {
        $this->artisan('analytics:demo-refresh')->assertSuccessful();
        $morning = Event::count();

        $this->travelTo('2026-06-15 20:00:00');
        $this->artisan('analytics:demo-refresh')->assertSuccessful();
        $incremental = Event::orderBy('uuid')->pluck('uuid')->all();

        $this->assertGreaterThan($morning, count($incremental));

        // Building from scratch at the same moment produces exactly the same events.
        Game::query()->delete();
        Cache::flush();
        $this->artisan('analytics:demo-refresh')->assertSuccessful();

        $this->assertSame($incremental, Event::orderBy('uuid')->pluck('uuid')->all());
    }

    public function test_the_history_is_rebuilt_once_the_day_rolls_over(): void
    {
        $this->artisan('analytics:demo-refresh')->assertSuccessful();
        $oldIds = Game::pluck('id')->all();

        $this->travelTo('2026-06-16 00:05:00');
        $this->artisan('analytics:demo-refresh')->expectsOutputToContain('Rebuilding Starfall Tactics')->assertSuccessful();

        $this->assertEmpty(array_intersect($oldIds, Game::pluck('id')->all()));
        $this->assertSame(2, Game::count());
        $this->assertGreaterThanOrEqual('2026-06-15', Event::min('occurred_on'));
    }

    public function test_it_picks_up_where_the_seeder_left_off(): void
    {
        $this->seed();
        $seeded = Event::count();

        $this->travelTo('2026-06-15 19:30:00');
        $this->artisan('analytics:demo-refresh')->doesntExpectOutputToContain('Rebuilding')->assertSuccessful();

        $this->assertGreaterThan($seeded, Event::count());
        $this->assertSame(Event::count(), Event::distinct()->count('uuid'));
    }
}
