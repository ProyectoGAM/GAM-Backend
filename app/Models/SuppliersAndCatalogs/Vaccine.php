<?php

namespace App\Models\SuppliersAndCatalogs;

use Carbon\CarbonImmutable;
use Database\Factories\SuppliersAndCatalogs\VaccineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $product_id
 * @property int $supplier_id
 * @property string $description
 * @property string|null $details
 * @property string $supplier_name_snapshot
 * @property int $created_by
 * @property string $created_by_name
 * @property string $operation_id
 * @property string $idempotency_key
 * @property string $request_hash
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Vaccine extends Model
{
    /** @use HasFactory<VaccineFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected static function booted(): void
    {
        static::creating(function (self $vaccine): void {
            $vaccine->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'supplier_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
