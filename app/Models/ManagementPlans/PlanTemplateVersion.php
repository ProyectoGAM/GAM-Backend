<?php

namespace App\Models\ManagementPlans;

use Database\Factories\ManagementPlans\PlanTemplateVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property int $id @property int $plan_template_id @property int $number @property string $status */
class PlanTemplateVersion extends Model
{
    /** @use HasFactory<PlanTemplateVersionFactory> */
    use HasFactory;

    /** @return BelongsTo<PlanTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(PlanTemplate::class, 'plan_template_id');
    }

    /** @return HasMany<PlanTemplateActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(PlanTemplateActivity::class)->orderBy('sort_order');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['number' => 'integer', 'published_at' => 'immutable_datetime'];
    }
}
