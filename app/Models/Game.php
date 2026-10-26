<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug'])]
#[Hidden(['ingest_key_hash'])]
class Game extends Model
{
    use HasFactory;

    public const KEY_PREFIX = 'gk_';

    protected function casts(): array
    {
        return [
            'building' => 'boolean',
            'ingest_key_rotated_at' => 'datetime',
        ];
    }

    /**
     * Games the dashboard lists: not a demo history that is still being built.
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where('building', false);
    }

    public static function hashKey(string $plainKey): string
    {
        return hash('sha256', $plainKey);
    }

    public static function findByIngestKey(string $plainKey): ?self
    {
        return static::where('ingest_key_hash', static::hashKey($plainKey))->first();
    }

    /**
     * Issue a fresh ingest key. The plaintext is returned once and never stored.
     */
    public function rotateIngestKey(): string
    {
        $plain = self::KEY_PREFIX.Str::random(40);

        $this->forceFill([
            'ingest_key_hash' => static::hashKey($plain),
            'ingest_key_prefix' => substr($plain, 0, 10),
            'ingest_key_rotated_at' => now(),
        ])->save();

        return $plain;
    }

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(GameSession::class);
    }

    public function funnels(): HasMany
    {
        return $this->hasMany(Funnel::class);
    }
}
