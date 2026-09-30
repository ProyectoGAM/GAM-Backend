<?php

namespace App\Models\ManagementPlans;

use Database\Factories\ManagementPlans\FlockPlanActivityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $public_id
 * @property int $flock_plan_revision_id
 * @property int $sort_order
 * @property string $type
 * @property string $title
 * @property string $timing_kind
 * @property int|null $start_day
 * @property int|null $end_day
 * @property int|null $start_week
 * @property int|null $end_week
 * @property int|null $interval_days
 * @property bool $conditional
 * @property string|null $condition
 * @property string|null $notes
 * @property string|null $catalog_type
 * @property int|null $catalog_id
 * @property array<string, mixed>|null $catalog_snapshot
 * @property int|null $source_template_activity_id
 */
class FlockPlanActivity extends Model
{
    /** @use HasFactory<FlockPlanActivityFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<FlockPlanRevision, $this> */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(FlockPlanRevision::class, 'flock_plan_revision_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer', 'start_day' => 'integer', 'end_day' => 'integer',
            'start_week' => 'integer', 'end_week' => 'integer', 'interval_days' => 'integer',
            'conditional' => 'boolean', 'catalog_snapshot' => 'array',
        ];
    }
}
