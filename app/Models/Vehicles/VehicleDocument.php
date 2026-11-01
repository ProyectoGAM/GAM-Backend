<?php

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['vehicle_id', 'uploaded_by', 'type', 'title', 'expires_at', 'storage_path', 'original_name', 'mime_type', 'size_bytes'])]
class VehicleDocument extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $document) => $document->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'date', 'size_bytes' => 'integer'];
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
