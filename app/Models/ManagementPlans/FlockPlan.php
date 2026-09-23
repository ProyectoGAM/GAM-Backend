<?php

namespace App\Models\ManagementPlans;

use App\Models\Lots\Flock;
use Carbon\CarbonImmutable;
use Database\Factories\ManagementPlans\FlockPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $flock_id
 * @property int $current_revision
 * @property CarbonImmutable $baseline_date
 * @property int|null $plan_template_version_id
 * @property int|null $source_flock_id
 */
class FlockPlan extends Model
{
    /** @use HasFactory<FlockPlanFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Flock, $this> */
    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    /** @return HasMany<FlockPlanRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(FlockPlanRevision::class)->orderBy('number');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['baseline_date' => 'immutable_date', 'current_revision' => 'integer'];
    }
}
