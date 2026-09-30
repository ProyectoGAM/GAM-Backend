<?php

namespace App\Models\Lots;

use Database\Factories\Lots\WeighingReferenceSettingsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property bool $singleton_key
 * @property int $adult_from_week
 * @property string $chick_min_weight_g
 * @property string $chick_max_weight_g
 * @property string $adult_min_weight_g
 * @property string $adult_max_weight_g
 * @property string $captured_unit
 * @property int $version
 */
class WeighingReferenceSettings extends Model
{
    /** @use HasFactory<WeighingReferenceSettingsFactory> */
    use HasFactory;

    protected $table = 'weighing_reference_settings';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'singleton_key' => 'boolean',
            'adult_from_week' => 'integer',
            'version' => 'integer',
        ];
    }
}
