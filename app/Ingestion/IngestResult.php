<?php

namespace App\Ingestion;

final readonly class IngestResult
{
    public function __construct(
        public int $accepted,
        public int $duplicates,
        public int $newPlayers,
    ) {}

    public function toArray(): array
    {
        return [
            'accepted' => $this->accepted,
            'duplicates' => $this->duplicates,
            'new_players' => $this->newPlayers,
        ];
    }
}
