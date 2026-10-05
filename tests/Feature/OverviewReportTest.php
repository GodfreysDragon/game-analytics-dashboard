<?php

namespace Tests\Feature;

use App\Analytics\DateRange;
use App\Analytics\OverviewReport;
use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsEvents;
use Tests\TestCase;

class OverviewReportTest extends TestCase
{
    use RefreshDatabase;
    use SendsEvents;

    public function test_it_reports_kpis_against_the_previous_period_and_fills_gaps_in_series(): void
    {
        $game = Game::factory()->create();

        $this->ingest($game, [
            // Previous period (Jun 1-2): one player, one session, no revenue.
            $this->event('session_start', 'old', '2026-06-01 10:00', ['session_id' => 'o1']),
            $this->event('session_end', 'old', '2026-06-01 10:10', ['session_id' => 'o1']),
            // Current period (Jun 3-4).
            $this->event('session_start', 'old', '2026-06-03 10:00', ['session_id' => 'o2']),
            $this->event('session_end', 'old', '2026-06-03 10:20', ['session_id' => 'o2']),
            $this->event('session_start', 'new', '2026-06-03 11:00', ['session_id' => 'n1', 'platform' => 'pc']),
            $this->event('purchase', 'new', '2026-06-03 11:05', ['session_id' => 'n1', 'value' => 9.99, 'platform' => 'pc']),
            $this->event('purchase', 'new', '2026-06-03 11:06', ['session_id' => 'n1', 'value' => 0.99, 'platform' => 'pc']),
            $this->event('session_end', 'new', '2026-06-03 11:10', ['session_id' => 'n1', 'platform' => 'pc']),
        ]);

        $report = app(OverviewReport::class)->build($game, DateRange::fromStrings('2026-06-03', '2026-06-04'));
        $kpis = collect($report['kpis'])->keyBy('key');

        $this->assertSame(['from' => '2026-06-01', 'to' => '2026-06-02', 'days' => 2], $report['previous_range']);
        $this->assertSame(2, $kpis['active_players']['value']);
        $this->assertSame(1, $kpis['active_players']['previous']);
        $this->assertSame(1.0, $kpis['active_players']['change']);
        $this->assertSame(1.0, $kpis['avg_dau']['value']);
        $this->assertSame(1, $kpis['new_players']['value']);
        $this->assertSame(2, $kpis['sessions']['value']);
        $this->assertSame(900, $kpis['avg_session_seconds']['value']);
        $this->assertSame(10.98, $kpis['revenue']['value']);
        $this->assertNull($kpis['revenue']['change'], 'Growth from zero is undefined, not infinite.');
        $this->assertSame(1, $kpis['paying_players']['value']);
        $this->assertSame(10.98, $kpis['arppu']['value']);
        $this->assertSame(5.49, $kpis['arpdau']['value']);

        $this->assertSame([['date' => '2026-06-03', 'value' => 2], ['date' => '2026-06-04', 'value' => 0]], $report['series']['dau']);
        $this->assertSame([['date' => '2026-06-03', 'value' => 10.98], ['date' => '2026-06-04', 'value' => 0]], $report['series']['revenue']);
        $this->assertEqualsCanonicalizing(
            [['label' => 'pc', 'players' => 1, 'share' => 0.5], ['label' => 'unknown', 'players' => 1, 'share' => 0.5]],
            $report['platforms'],
        );
    }
}
