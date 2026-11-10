<?php

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['vehicle_id', 'usage_id', 'created_by', 'idempotency_key', 'occurred_at', 'liters', 'fuel_type', 'amount', 'currency', 'supplier', 'odometer_km', 'receipt_path', 'receipt_sha256', 'receipt_original_name', 'notes'])]
class VehicleFuelEntry extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $entry) => $entry->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'liters' => 'decimal:3', 'amount' => 'decimal:3', 'odometer_km' => 'decimal:1'];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<VehicleUsage, $this> */
    public function usage(): BelongsTo
    {
        return $this->belongsTo(VehicleUsage::class, 'usage_id');
    }
}
