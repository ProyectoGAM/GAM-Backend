<?php

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['vehicle_id', 'usage_id', 'created_by', 'corrects_reading_id', 'idempotency_key', 'meter_type', 'event_type', 'event_reason', 'counter_epoch', 'value', 'recorded_at', 'received_at', 'source', 'correction_reason', 'notes'])]
class VehicleMeterReading extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $reading) => $reading->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @param Builder<VehicleMeterReading> $query @return Builder<VehicleMeterReading> */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($subquery) => $subquery->selectRaw('1')->from('vehicle_meter_readings as corrections')->whereColumn('corrections.corrects_reading_id', 'vehicle_meter_readings.id'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'decimal:1', 'counter_epoch' => 'integer', 'recorded_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
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

    /** @return BelongsTo<VehicleMeterReading, $this> */
    public function correctsReading(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corrects_reading_id');
    }
}
