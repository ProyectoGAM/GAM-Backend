<?php

namespace App\Models\Lots;

use Carbon\CarbonImmutable;
use Database\Factories\Lots\FlockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string $operation_id
 * @property string $type
 * @property int|null $source_flock_id
 * @property int|null $destination_flock_id
 * @property int|null $source_poultry_house_id
 * @property int|null $destination_poultry_house_id
 * @property int $quantity
 * @property array<string, array<string, mixed>> $before
 * @property array<string, array<string, mixed>> $after
 * @property string|null $reason
 * @property CarbonImmutable $occurred_at
 * @property int $created_by
 * @property int|null $reverses_movement_id
 * @property-read Flock|null $sourceFlock
 * @property-read Flock|null $destinationFlock
 * @property-read FlockMovement|null $reversedMovement
 */
class FlockMovement extends Model
{
    /** Conserva el instante aunque PostgreSQL use una zona horaria distinta de UTC. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @use HasFactory<FlockMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            $movement->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'quantity' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Flock, $this> */
    public function sourceFlock(): BelongsTo
    {
        return $this->belongsTo(Flock::class, 'source_flock_id');
    }

    /** @return BelongsTo<Flock, $this> */
    public function destinationFlock(): BelongsTo
    {
        return $this->belongsTo(Flock::class, 'destination_flock_id');
    }

    /** @return BelongsTo<FlockMovement, $this> */
    public function reversedMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }
}
