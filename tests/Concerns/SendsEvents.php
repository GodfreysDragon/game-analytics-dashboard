<?php

namespace Tests\Concerns;

use App\Ingestion\EventIngestor;
use App\Models\Game;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

trait SendsEvents
{
    /**
     * Build one event payload in the shape the ingestion API accepts.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function event(string $name, string $player, string $timestamp, array $extra = []): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'player_id' => $player,
            'timestamp' => CarbonImmutable::parse($timestamp, 'UTC')->toIso8601ZuluString('millisecond'),
            ...$extra,
        ];
    }

    /**
     * Write events straight through the ingestion service (skipping HTTP) to arrange report data.
     *
     * @param  list<array<string, mixed>>  $events
     */
    protected function ingest(Game $game, array $events): void
    {
        app(EventIngestor::class)->ingest($game, $events);
    }
}
