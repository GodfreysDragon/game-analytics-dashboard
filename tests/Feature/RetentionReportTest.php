<?php

namespace Tests\Feature;

use App\Analytics\DateRange;
use App\Analytics\RetentionReport;
use App\Models\Game;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsEvents;
use Tests\TestCase;

class RetentionReportTest extends TestCase
{
    use RefreshDatabase;
    use SendsEvents;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->game = Game::factory()->create();

        // Cohort Jun 1: four players. a returns D1+D2, b returns D1, c returns D2, d never returns.
        // Cohort Jun 2: two players. e returns D1 (Jun 3), f never returns.
        $this->ingest($this->game, [
            $this->event('session_start', 'a', '2026-06-01 09:00'),
            $this->event('session_start', 'a', '2026-06-01 21:00'),
            $this->event('session_start', 'a', '2026-06-02 09:00'),
            $this->event('session_start', 'a', '2026-06-03 09:00'),
            $this->event('session_start', 'b', '2026-06-01 10:00', ['platform' => 'ios']),
            $this->event('session_start', 'b', '2026-06-02 10:00', ['platform' => 'ios']),
            $this->event('session_start', 'c', '2026-06-01 11:00', ['platform' => 'ios']),
            $this->event('session_start', 'c', '2026-06-03 11:00', ['platform' => 'ios']),
            $this->event('session_start', 'd', '2026-06-01 12:00'),
            $this->event('session_start', 'e', '2026-06-02 12:00'),
            $this->event('session_start', 'e', '2026-06-03 12:00'),
            $this->event('session_start', 'f', '2026-06-02 13:00'),
        ]);
    }

    private function report(?string $platform = null): array
    {
        return app(RetentionReport::class)->build(
            $this->game,
            DateRange::fromStrings('2026-06-01', '2026-06-02'),
            maxDay: 2,
            platform: $platform,
            asOf: CarbonImmutable::parse('2026-06-03', 'UTC'),
        );
    }

    public function test_it_builds_the_cohort_matrix(): void
    {
        $report = $this->report();

        $this->assertSame(['date' => '2026-06-01', 'size' => 4, 'retained' => [4, 2, 2], 'rates' => [1.0, 0.5, 0.5]], $report['cohorts'][0]);
        $this->assertSame('2026-06-02', $report['cohorts'][1]['date']);
        $this->assertSame(2, $report['cohorts'][1]['size']);
        $this->assertSame([2, 1, null], $report['cohorts'][1]['retained']);
    }

    public function test_days_that_have_not_happened_yet_are_null_not_zero(): void
    {
        $report = $this->report();

        // The Jun 2 cohort's day 2 is Jun 4, after the as-of date.
        $this->assertNull($report['cohorts'][1]['rates'][2]);
    }

    public function test_the_average_is_weighted_by_cohort_size_and_skips_unobserved_days(): void
    {
        $average = $this->report()['average'];

        // D1: (2 + 1) / (4 + 2) = 0.5 over both cohorts; D2: only Jun 1 is observable -> 2 / 4.
        $this->assertSame(['day' => 1, 'rate' => 0.5, 'cohorts' => 2], $average[1]);
        $this->assertSame(['day' => 2, 'rate' => 0.5, 'cohorts' => 1], $average[2]);
    }

    public function test_it_can_be_filtered_by_platform(): void
    {
        $report = $this->report('ios');

        $this->assertSame([2, 1, 1], $report['cohorts'][0]['retained']);
        $this->assertSame(0, $report['cohorts'][1]['size']);
        $this->assertSame([null, null, null], $report['cohorts'][1]['rates']);
    }
}
