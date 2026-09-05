<?php

namespace App\Models\SuppliersAndCatalogs;

use Carbon\CarbonImmutable;
use Database\Factories\SuppliersAndCatalogs\MedicineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $description
 * @property int $supplier_id
 * @property string $supplier_name_snapshot
 * @property int $created_by
 * @property string $created_by_name
 * @property string $operation_id
 * @property string $idempotency_key
 * @property string $request_hash
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Medicine extends Model
{
    /** @use HasFactory<MedicineFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected static function booted(): void
    {
        static::creating(function (self $medicine): void {
            $medicine->public_id ??= (string) Str::ulid();
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
            'supplier_id' => 'integer',
            'created_by' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
