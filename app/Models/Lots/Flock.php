<?php

namespace App\Models\Lots;

use App\Modules\Lots\Domain\Enums\FlockStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Lots\FlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string $code
 * @property int $breed_id
 * @property int|null $supplier_id
 * @property string|null $origin
 * @property string|null $supplier_name
 * @property int $poultry_house_id
 * @property int $production_unit_id
 * @property int $initial_quantity
 * @property int $current_quantity
 * @property CarbonImmutable $entry_date
 * @property CarbonImmutable $established_at
 * @property FlockStatus $status
 * @property int $version
 * @property string|null $notes
 * @property CarbonImmutable|null $finalized_at
 * @property string|null $finalization_reason
 */
#[Fillable(['code', 'notes'])]
class Flock extends Model
{
    /** Conserva el instante aunque PostgreSQL use una zona horaria distinta de UTC. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @use HasFactory<FlockFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (self $flock): void {
            $flock->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'established_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'initial_quantity' => 'integer',
            'current_quantity' => 'integer',
            'version' => 'integer',
            'status' => FlockStatus::class,
        ];
    }

    /** @return BelongsTo<Breed, $this> */
    public function breed(): BelongsTo
    {
        return $this->belongsTo(Breed::class);
    }
}
