<?php

namespace App\Models\Geography;

use App\Models\FarmStructure\ProductionUnit;
use Database\Factories\Geography\LocalityFactory;
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
 * @property int $department_id
 * @property string $name
 * @property string $normalized_name
 * @property-read Department $department
 * @property-read Carbon $created_at
 * @property-read Carbon $updated_at
 */
#[Fillable(['department_id', 'name'])]
class Locality extends Model
{
    /** @use HasFactory<LocalityFactory> */
    use HasFactory;

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

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<ProductionUnit, $this> */
    public function productionUnits(): HasMany
    {
        return $this->hasMany(ProductionUnit::class);
    }
}
