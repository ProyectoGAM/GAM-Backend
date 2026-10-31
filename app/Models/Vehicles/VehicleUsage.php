<?php

namespace App\Models\Vehicles;

use App\Models\Deliveries\Delivery;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['vehicle_id', 'driver_id', 'delivery_id', 'active_vehicle_key', 'purpose', 'notes', 'started_at', 'ended_at', 'start_odometer_km', 'end_odometer_km', 'vehicle_snapshot', 'driver_snapshot', 'idempotency_key'])]
class VehicleUsage extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $usage): void {
            $usage->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'start_odometer_km' => 'decimal:1', 'end_odometer_km' => 'decimal:1', 'vehicle_snapshot' => 'array'];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<User, $this> */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id')->withTrashed();
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** @return HasMany<VehicleMeterReading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(VehicleMeterReading::class, 'usage_id');
    }

    /** @return HasMany<VehicleFuelEntry, $this> */
    public function fuelEntries(): HasMany
    {
        return $this->hasMany(VehicleFuelEntry::class, 'usage_id');
    }
}
