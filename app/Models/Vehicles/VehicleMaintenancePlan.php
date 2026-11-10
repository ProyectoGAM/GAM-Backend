<?php

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['vehicle_id', 'task', 'interval_days', 'interval_km', 'last_completed_at', 'last_odometer_km', 'next_due_at', 'next_due_odometer_km', 'next_due_odometer_epoch', 'active', 'version'])]
class VehicleMaintenancePlan extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $plan) => $plan->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['interval_days' => 'integer', 'interval_km' => 'decimal:1', 'last_completed_at' => 'date', 'last_odometer_km' => 'decimal:1', 'next_due_at' => 'date', 'next_due_odometer_km' => 'decimal:1', 'next_due_odometer_epoch' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
