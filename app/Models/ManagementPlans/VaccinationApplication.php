<?php

namespace App\Models\ManagementPlans;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockLocation;
use App\Models\Lots\Flock;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\SuppliersAndCatalogs\Vaccine;
use App\Models\User;
use Database\Factories\ManagementPlans\VaccinationApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** @property string $public_id @property string $operation_id @property array<string, mixed> $vaccine_snapshot @property array<string, mixed> $product_snapshot */
class VaccinationApplication extends Model
{
    /** @use HasFactory<VaccinationApplicationFactory> */
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
        return ['occurred_at' => 'immutable_datetime', 'inventory_quantity' => 'decimal:6', 'vaccine_snapshot' => 'array', 'product_snapshot' => 'array'];
    }

    /** @return BelongsTo<Flock, $this> */
    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    /** @return BelongsTo<Vaccine, $this> */
    public function vaccine(): BelongsTo
    {
        return $this->belongsTo(Vaccine::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<StockLocation, $this> */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    /** @return BelongsTo<InventoryMovement, $this> */
    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
