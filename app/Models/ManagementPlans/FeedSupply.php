<?php

namespace App\Models\ManagementPlans;

use App\Models\Lots\Flock;
use Database\Factories\ManagementPlans\FeedSupplyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedSupply extends Model
{
    /** @use HasFactory<FeedSupplyFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:sP';

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return ['quantity' => 'decimal:6', 'occurred_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Flock, $this> */
    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }
}
