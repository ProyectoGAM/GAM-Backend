<?php

namespace App\Models\Lots;

use Carbon\CarbonImmutable;
use Database\Factories\Lots\EggCollectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $flock_id
 * @property int $poultry_house_id
 * @property int $production_unit_id
 * @property int $quantity
 * @property int $version
 * @property string $status
 * @property string|null $notes
 * @property CarbonImmutable $occurred_at
 * @property int $created_by
 * @property int $product_id
 * @property int $stock_location_id
 * @property int|null $inventory_movement_id
 * @property-read Flock $flock
 */
class EggCollection extends Model
{
    /** Conserva el instante aunque PostgreSQL use una zona horaria distinta de UTC. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @use HasFactory<EggCollectionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $record): void {
            $record->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'version' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Flock, $this> */
    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }
}
