<?php

namespace App\Models\ManagementPlans;

use Carbon\CarbonImmutable;
use Database\Factories\ManagementPlans\FlockPlanRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $flock_plan_id
 * @property int $number
 * @property string $operation_id
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 */
class FlockPlanRevision extends Model
{
    /** @use HasFactory<FlockPlanRevisionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<FlockPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(FlockPlan::class, 'flock_plan_id');
    }

    /** @return HasMany<FlockPlanActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(FlockPlanActivity::class)->orderBy('sort_order');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['number' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
