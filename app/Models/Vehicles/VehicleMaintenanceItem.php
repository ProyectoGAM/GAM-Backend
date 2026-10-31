<?php

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['maintenance_id', 'plan_id', 'task', 'completed', 'notes'])]
class VehicleMaintenanceItem extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $item) => $item->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['completed' => 'boolean'];
    }

    /** @return BelongsTo<VehicleMaintenance, $this> */
    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(VehicleMaintenance::class, 'maintenance_id');
    }

    /** @return BelongsTo<VehicleMaintenancePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(VehicleMaintenancePlan::class, 'plan_id');
    }
}
