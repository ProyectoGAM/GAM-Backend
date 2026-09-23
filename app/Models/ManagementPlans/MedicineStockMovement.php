<?php

namespace App\Models\ManagementPlans;

use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\User;
use Database\Factories\ManagementPlans\MedicineStockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** @property string $public_id @property string $operation_id @property int $quantity_delta @property int $balance_after */
class MedicineStockMovement extends Model
{
    /** @use HasFactory<MedicineStockMovementFactory> */
    use HasFactory;

    /** Conserva el instante al guardar fechas con zona horaria en PostgreSQL. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            $movement->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity_delta' => 'integer', 'balance_after' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Medicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
