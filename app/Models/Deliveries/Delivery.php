<?php

namespace App\Models\Deliveries;

use App\Enums\Deliveries\DeliveryStatus;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use Database\Factories\Deliveries\DeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $driver_id
 * @property int $production_unit_id
 * @property string|null $vehicle_reference
 * @property DeliveryStatus $status
 * @property int $loaded_quantity
 * @property int $returned_quantity
 * @property string $start_idempotency_key
 * @property string $start_request_hash
 * @property string|null $close_idempotency_key
 * @property string|null $close_request_hash
 * @property Carbon $started_at
 * @property Carbon|null $closed_at
 * @property string|null $close_notes
 */
#[Fillable([
    'public_id', 'driver_id', 'production_unit_id', 'vehicle_reference', 'status',
    'loaded_quantity', 'returned_quantity', 'start_idempotency_key', 'start_request_hash',
    'close_idempotency_key', 'close_request_hash', 'started_at', 'closed_at', 'close_notes',
])]
class Delivery extends Model
{
    /** @use HasFactory<DeliveryFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $delivery): void {
            $delivery->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'loaded_quantity' => 'integer',
            'returned_quantity' => 'integer',
            'started_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /** @return BelongsTo<ProductionUnit, $this> */
    public function productionUnit(): BelongsTo
    {
        return $this->belongsTo(ProductionUnit::class);
    }

    /** @return HasMany<DeliveryLoad, $this> */
    public function loads(): HasMany
    {
        return $this->hasMany(DeliveryLoad::class);
    }

    /** @return HasMany<DeliveryStop, $this> */
    public function stops(): HasMany
    {
        return $this->hasMany(DeliveryStop::class);
    }

    /** @return HasMany<DeliveryLocationPoint, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(DeliveryLocationPoint::class);
    }

    /** @return HasOne<DeliveryLocationPoint, $this> */
    public function latestLocation(): HasOne
    {
        return $this->hasOne(DeliveryLocationPoint::class)->latestOfMany('captured_at');
    }
}
