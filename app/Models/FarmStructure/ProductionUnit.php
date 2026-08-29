<?php

namespace App\Models\FarmStructure;

use App\Models\Geography\Locality;
use App\Modules\FarmStructure\Domain\Enums\ProductionUnitStatus;
use Database\Factories\FarmStructure\ProductionUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $locality_id
 * @property string $name
 * @property string $normalized_name
 * @property string $latitude
 * @property string $longitude
 * @property ProductionUnitStatus $status
 * @property int|null $poultry_houses_count
 * @property-read Locality $locality
 * @property-read Carbon $created_at
 * @property-read Carbon $updated_at
 */
#[Fillable(['locality_id', 'name', 'latitude', 'longitude', 'status'])]
class ProductionUnit extends Model
{
    /** @use HasFactory<ProductionUnitFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'status' => ProductionUnitStatus::class,
        ];
    }

    /**
     * Normaliza el nombre usado por las restricciones de unicidad.
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): array => [
                'name' => trim($value),
                'normalized_name' => Str::lower(trim($value)),
            ],
        );
    }

    /** @return BelongsTo<Locality, $this> */
    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }

    /** @return HasMany<PoultryHouse, $this> */
    public function poultryHouses(): HasMany
    {
        return $this->hasMany(PoultryHouse::class);
    }
}
