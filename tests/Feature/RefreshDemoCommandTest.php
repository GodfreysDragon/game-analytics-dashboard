<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Game;
use App\Models\User;
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
        $this->assertDatabaseHas('users', ['email' => 'admin@example.com']);
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

    public function test_a_rebuild_paused_between_runs_stays_hidden_until_it_is_complete(): void
    {
        $this->artisan('analytics:demo-refresh')->assertSuccessful();
        $old = Game::firstWhere('slug', 'starfall-tactics');
        $oldEvents = $old->events()->count();

        $this->travelTo('2026-06-16 00:05:00');
        $this->artisan('analytics:demo-refresh --max-seconds=0')->expectsOutputToContain('continuing on the next run')->assertSuccessful();

        // Visitors still get the old history, untouched, while the new one is built out of sight.
        $this->actingAs(User::factory()->create())->getJson('/api/admin/games')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', $old->id);
        $this->assertSame($oldEvents, $old->events()->count());
        $this->assertTrue(Game::where('building', true)->where('name', 'Starfall Tactics')->exists());

        for ($runs = 1; Game::where('building', true)->exists(); $runs++) {
            $this->assertLessThan(100, $runs, 'The rebuild never finished.');
            $this->artisan('analytics:demo-refresh --max-seconds=0')->assertSuccessful();
        }

        $this->assertModelMissing($old);
        $this->assertEqualsCanonicalizing(['starfall-tactics', 'pocket-harvest'], Game::pluck('slug')->all());
        $rebuilt = Event::orderBy('uuid')->pluck('uuid')->all();

        // Built in steps, the history is exactly what a single run builds.
        Game::query()->delete();
        Cache::flush();
        $this->artisan('analytics:demo-refresh')->assertSuccessful();

        $this->assertSame($rebuilt, Event::orderBy('uuid')->pluck('uuid')->all());
    }

    public function test_a_build_left_over_from_an_earlier_day_is_started_again(): void
    {
        $this->artisan('analytics:demo-refresh')->assertSuccessful();

        $this->travelTo('2026-06-16 00:05:00');
        $this->artisan('analytics:demo-refresh --max-seconds=0')->assertSuccessful();
        $stale = Game::where('building', true)->pluck('id')->all();

        $this->travelTo('2026-06-17 00:05:00');
        $this->artisan('analytics:demo-refresh')->assertSuccessful();

        $this->assertSame(0, Game::whereIn('id', $stale)->count());
        $this->assertSame(0, Game::where('building', true)->count());
        $this->assertSame(2, Game::count());
        $this->assertGreaterThanOrEqual('2026-06-16', Event::min('occurred_on'));
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
