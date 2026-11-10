<?php

namespace App\Models\Vehicles;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['maintenance_id', 'uploaded_by', 'title', 'storage_path', 'original_name', 'mime_type', 'size_bytes'])]
class VehicleMaintenanceDocument extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $document) => $document->public_id ??= (string) Str::ulid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<VehicleMaintenance, $this> */
    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(VehicleMaintenance::class, 'maintenance_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }
}
