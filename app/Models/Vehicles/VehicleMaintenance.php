<?php

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['vehicle_id', 'created_by', 'completed_by', 'type', 'status', 'immobilizes_vehicle', 'workshop', 'description', 'notes', 'planned_at', 'started_at', 'completed_at', 'odometer_km', 'cost_amount', 'cost_currency', 'idempotency_key', 'request_hash', 'cancellation_reason', 'version'])]
class VehicleMaintenance extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $maintenance) => $maintenance->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['immobilizes_vehicle' => 'boolean', 'planned_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'odometer_km' => 'decimal:1', 'cost_amount' => 'decimal:3', 'version' => 'integer'];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return HasMany<VehicleMaintenanceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceItem::class, 'maintenance_id');
    }

    /** @return HasMany<VehicleMaintenanceDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceDocument::class, 'maintenance_id');
    }
}
