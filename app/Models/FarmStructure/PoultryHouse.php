<?php

namespace App\Models\FarmStructure;

use App\Modules\FarmStructure\Domain\Enums\PoultryHouseStatus;
use Database\Factories\FarmStructure\PoultryHouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $production_unit_id
 * @property string $name
 * @property string $normalized_name
 * @property int $bird_capacity
 * @property PoultryHouseStatus $status
 * @property-read ProductionUnit $productionUnit
 * @property-read Carbon $created_at
 * @property-read Carbon $updated_at
 */
#[Fillable(['production_unit_id', 'name', 'bird_capacity', 'status'])]
class PoultryHouse extends Model
{
    /** @use HasFactory<PoultryHouseFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bird_capacity' => 'integer',
            'status' => PoultryHouseStatus::class,
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

    /** @return BelongsTo<ProductionUnit, $this> */
    public function productionUnit(): BelongsTo
    {
        return $this->belongsTo(ProductionUnit::class);
    }
}
