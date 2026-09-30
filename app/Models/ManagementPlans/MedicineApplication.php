<?php

namespace App\Models\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\User;
use Database\Factories\ManagementPlans\MedicineApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** @property string $public_id @property string $operation_id */
class MedicineApplication extends Model
{
    /** @use HasFactory<MedicineApplicationFactory> */
    use HasFactory;

    /** Conserva el instante al guardar fechas con zona horaria en PostgreSQL. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected static function booted(): void
    {
        static::creating(function (self $application): void {
            $application->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'quantity' => 'integer'];
    }

    /** @return BelongsTo<Flock, $this> */
    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    /** @return BelongsTo<Medicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /** @return BelongsTo<MedicineStockMovement, $this> */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(MedicineStockMovement::class, 'stock_movement_id');
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
