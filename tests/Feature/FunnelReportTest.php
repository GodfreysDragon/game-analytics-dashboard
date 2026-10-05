<?php

namespace Tests\Feature;

use App\Analytics\DateRange;
use App\Analytics\FunnelReport;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsEvents;
use Tests\TestCase;

class FunnelReportTest extends TestCase
{
    use RefreshDatabase;
    use SendsEvents;

    private const STEPS = ['first_open', 'tutorial_complete', 'purchase'];

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = Game::factory()->create();
    }

    /**
     * @param  list<string>  $steps
     * @return array<string, mixed>
     */
    private function report(array $steps = self::STEPS, int $windowMinutes = 60, ?string $platform = null): array
    {
        return app(FunnelReport::class)->build($this->game, $steps, $windowMinutes, DateRange::fromStrings('2026-06-01', '2026-06-01'), $platform);
    }

    /**
     * @return list<int>
     */
    private function counts(array $report): array
    {
        return array_column($report['steps'], 'players');
    }

    public function test_it_counts_players_reaching_each_step_in_order(): void
    {
        $this->ingest($this->game, [
            $this->event('first_open', 'full', '2026-06-01 10:00'),
            $this->event('tutorial_complete', 'full', '2026-06-01 10:05'),
            $this->event('purchase', 'full', '2026-06-01 10:20'),
            $this->event('first_open', 'two_steps', '2026-06-01 11:00'),
            $this->event('tutorial_complete', 'two_steps', '2026-06-01 11:10'),
            $this->event('first_open', 'entered_only', '2026-06-01 12:00'),
        ]);

        $report = $this->report();

        $this->assertSame([3, 2, 1], $this->counts($report));
        $this->assertSame([1.0, 0.6667, 0.3333], array_column($report['steps'], 'conversion'));
        $this->assertSame([1.0, 0.6667, 0.5], array_column($report['steps'], 'step_conversion'));
        $this->assertSame([0, 1, 1], array_column($report['steps'], 'dropped'));
    }

    public function test_steps_out_of_order_do_not_count(): void
    {
        $this->ingest($this->game, [
            $this->event('tutorial_complete', 'p', '2026-06-01 09:59'),
            $this->event('first_open', 'p', '2026-06-01 10:00'),
            $this->event('purchase', 'p', '2026-06-01 10:01'),
        ]);

        $this->assertSame([1, 0, 0], $this->counts($this->report()));
    }

    public function test_steps_after_the_conversion_window_do_not_count(): void
    {
        $this->ingest($this->game, [
            $this->event('first_open', 'p', '2026-06-01 10:00'),
            $this->event('tutorial_complete', 'p', '2026-06-01 10:30'),
            $this->event('purchase', 'p', '2026-06-01 11:01'),
        ]);

        $this->assertSame([1, 1, 0], $this->counts($this->report(windowMinutes: 60)));
        $this->assertSame([1, 1, 1], $this->counts($this->report(windowMinutes: 61)));
    }

    public function test_the_furthest_reaching_attempt_is_used(): void
    {
        // The first attempt stalls; a later entry converts fully.
        $this->ingest($this->game, [
            $this->event('session_start', 'p', '2026-06-01 08:00'),
            $this->event('store_open', 'p', '2026-06-01 08:01'),
            $this->event('session_start', 'p', '2026-06-01 20:00'),
            $this->event('store_open', 'p', '2026-06-01 20:01'),
            $this->event('purchase', 'p', '2026-06-01 20:03'),
        ]);

        $report = $this->report(['session_start', 'store_open', 'purchase'], 30);

        $this->assertSame([1, 1, 1], $this->counts($report));
        $this->assertSame([null, 60, 120], array_column($report['steps'], 'median_seconds'));
    }

    public function test_only_entries_inside_the_date_range_count_but_later_steps_may_finish_after_it(): void
    {
        $this->ingest($this->game, [
            $this->event('first_open', 'before', '2026-05-31 23:50'),
            $this->event('tutorial_complete', 'before', '2026-06-01 00:05'),
            $this->event('first_open', 'late', '2026-06-01 23:50'),
            $this->event('tutorial_complete', 'late', '2026-06-02 00:10'),
        ]);

        $this->assertSame([1, 1, 0], $this->counts($this->report()));
    }

    public function test_a_step_name_can_repeat(): void
    {
        $this->ingest($this->game, [
            $this->event('level_fail', 'p', '2026-06-01 10:00'),
            $this->event('level_fail', 'p', '2026-06-01 10:02'),
            $this->event('level_fail', 'q', '2026-06-01 10:00'),
        ]);

        $this->assertSame([2, 1], $this->counts($this->report(['level_fail', 'level_fail'])));
    }

    public function test_it_breaks_down_by_platform_and_filters_by_platform(): void
    {
        $this->ingest($this->game, [
            $this->event('first_open', 'i1', '2026-06-01 10:00', ['platform' => 'ios']),
            $this->event('tutorial_complete', 'i1', '2026-06-01 10:01', ['platform' => 'ios']),
            $this->event('purchase', 'i1', '2026-06-01 10:02', ['platform' => 'ios']),
            $this->event('first_open', 'i2', '2026-06-01 10:00', ['platform' => 'ios']),
            $this->event('first_open', 'a1', '2026-06-01 10:00', ['platform' => 'android']),
        ]);

        $report = $this->report();
        $this->assertSame([
            ['label' => 'ios', 'counts' => [2, 1, 1], 'conversion' => 0.5],
            ['label' => 'android', 'counts' => [1, 0, 0], 'conversion' => 0.0],
        ], $report['breakdown']);

        $this->assertSame([1, 0, 0], $this->counts($this->report(platform: 'android')));
    }

    public function test_an_empty_funnel_reports_zeroes_without_dividing_by_zero(): void
    {
        $report = $this->report();

        $this->assertSame([0, 0, 0], $this->counts($report));
        $this->assertSame([null, null, null], array_column($report['steps'], 'conversion'));
    }
}
