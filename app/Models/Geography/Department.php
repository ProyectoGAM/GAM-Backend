<?php

namespace App\Models\Geography;

use Database\Factories\Geography\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $normalized_name
 * @property int|null $localities_count
 * @property-read Carbon $created_at
 * @property-read Carbon $updated_at
 */
#[Fillable(['name'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
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

    /** @return HasMany<Locality, $this> */
    public function localities(): HasMany
    {
        return $this->hasMany(Locality::class);
    }
}
