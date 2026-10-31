<?php

namespace App\Models\Vehicles;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['plate', 'plate_normalized', 'make', 'model', 'year', 'admitted_at', 'status', 'status_reason', 'capacity_kg', 'habitual_driver_id', 'notes', 'version'])]
class Vehicle extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $vehicle): void {
            $vehicle->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['admitted_at' => 'date', 'capacity_kg' => 'decimal:2', 'version' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function habitualDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'habitual_driver_id')->withTrashed();
    }

    /** @return HasMany<VehicleUsage, $this> */
    public function usages(): HasMany
    {
        return $this->hasMany(VehicleUsage::class);
    }

    /** @return HasMany<VehicleStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(VehicleStatusChange::class);
    }

    /** @return HasMany<VehicleMeterReading, $this> */
    public function readings(): HasMany
    {
        return $this->hasMany(VehicleMeterReading::class);
    }

    /** @return HasMany<VehicleFuelEntry, $this> */
    public function fuelEntries(): HasMany
    {
        return $this->hasMany(VehicleFuelEntry::class);
    }

    /** @return HasMany<VehicleMaintenancePlan, $this> */
    public function maintenancePlans(): HasMany
    {
        return $this->hasMany(VehicleMaintenancePlan::class);
    }

    /** @return HasMany<VehicleMaintenance, $this> */
    public function maintenances(): HasMany
    {
        return $this->hasMany(VehicleMaintenance::class);
    }

    /** @return HasMany<VehicleDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return ['id' => $this->public_id, 'plate' => $this->plate, 'make' => $this->make, 'model' => $this->model, 'year' => $this->year];
    }
}
