<?php

namespace App\Models\ManagementPlans;

use Database\Factories\ManagementPlans\PlanTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id @property string $public_id @property string $name @property string $status @property int $current_version @property int|null $published_version */
class PlanTemplate extends Model
{
    /** @use HasFactory<PlanTemplateFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return HasMany<PlanTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanTemplateVersion::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['current_version' => 'integer', 'published_version' => 'integer'];
    }
}
