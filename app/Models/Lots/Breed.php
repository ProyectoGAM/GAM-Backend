<?php

namespace App\Models\Lots;

use Database\Factories\Lots\BreedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $status
 * @property int $version
 * @property string|null $chick_min_weight_g
 * @property string|null $chick_max_weight_g
 * @property string|null $adult_min_weight_g
 * @property string|null $adult_max_weight_g
 */
#[Fillable(['name'])]
class Breed extends Model
{
    /** Conserva el instante aunque PostgreSQL use una zona horaria distinta de UTC. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @use HasFactory<BreedFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    /** Normaliza el nombre sin alterar la presentación pública. */
    protected function name(): Attribute
    {
        return Attribute::make(set: fn (string $value): array => [
            'name' => trim($value),
            'normalized_name' => Str::lower(trim($value)),
        ]);
    }
}
