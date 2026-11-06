<?php

namespace App\Models\Vehicles;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['vehicle_id', 'uploaded_by', 'type', 'title', 'expires_at', 'storage_path', 'original_name', 'mime_type', 'size_bytes'])]
class VehicleDocument extends Model
{
    public const int EXPIRY_NOTICE_DAYS = 30;

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

    /** @param Builder<self> $query @return Builder<self> */
    public function scopeCurrentExpiring(Builder $query): Builder
    {
        return $query->whereNotNull('vehicle_documents.expires_at')->whereNotExists(fn ($renewal) => $renewal
            ->selectRaw('1')->from('vehicle_documents as renewal')
            ->whereColumn('renewal.vehicle_id', 'vehicle_documents.vehicle_id')
            ->whereRaw('lower(trim(renewal.type)) = lower(trim(vehicle_documents.type))')
            ->whereRaw('lower(trim(renewal.title)) = lower(trim(vehicle_documents.title))')
            ->where(fn ($later) => $later->whereColumn('renewal.expires_at', '>', 'vehicle_documents.expires_at')
                ->orWhere(fn ($sameExpiry) => $sameExpiry->whereColumn('renewal.expires_at', 'vehicle_documents.expires_at')
                    ->whereColumn('renewal.id', '<', 'vehicle_documents.id'))));
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
