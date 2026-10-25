<?php

namespace Tests\Feature;

use App\Models\Funnel;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    private Game $game;

    private Funnel $funnel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['analytics.demo_mode' => true]);

        $this->game = Game::factory()->create();
        $this->funnel = $this->game->funnels()->create(['name' => 'Onboarding', 'steps' => ['first_open', 'tutorial_complete'], 'window_minutes' => 60]);
        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function writeEndpoints(): array
    {
        return [
            'create a game' => ['post', '/api/admin/games'],
            'rotate a key' => ['post', '/api/admin/games/{game}/rotate-key'],
            'create a funnel' => ['post', '/api/admin/games/{game}/funnels'],
            'update a funnel' => ['put', '/api/admin/games/{game}/funnels/{funnel}'],
            'delete a funnel' => ['delete', '/api/admin/games/{game}/funnels/{funnel}'],
        ];
    }

    #[DataProvider('writeEndpoints')]
    public function test_writes_are_refused(string $method, string $uri): void
    {
        $keyHash = $this->game->ingest_key_hash;
        $uri = str_replace(['{game}', '{funnel}'], [(string) $this->game->id, (string) $this->funnel->id], $uri);

        $this->json($method, $uri, ['name' => 'Changed', 'steps' => ['a_step', 'b_step'], 'window_minutes' => 30])
            ->assertForbidden()
            ->assertJsonPath('message', 'This is a read-only demo, so changes are turned off.');

        $this->assertSame(1, Game::count());
        $this->assertSame($keyHash, $this->game->fresh()->ingest_key_hash);
        $this->assertSame('Onboarding', $this->funnel->fresh()->name);
    }

    public function test_reports_and_funnel_previews_still_work(): void
    {
        $this->getJson("/api/admin/games/{$this->game->id}/funnels")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/admin/games/{$this->game->id}/funnels/{$this->funnel->id}/report")->assertOk();
        $this->getJson("/api/admin/games/{$this->game->id}/funnels/preview?".http_build_query(['steps' => ['first_open', 'level_start'], 'window_minutes' => 30]))->assertOk();
        $this->getJson("/api/admin/games/{$this->game->id}/overview")->assertOk();
    }

    public function test_the_page_tells_the_dashboard_it_is_a_demo(): void
    {
        $this->withoutVite();

        $this->get('/login')->assertOk()->assertSee('<meta name="demo-mode" content="1">', false);

        config(['analytics.demo_mode' => false]);
        $this->get('/login')->assertSee('<meta name="demo-mode" content="0">', false);
    }
}
