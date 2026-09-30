<?php

namespace App\Models\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ManagementPlans\ManagementExecutionCorrectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property string $public_id
 * @property string $operation_id
 * @property CarbonImmutable $occurred_at
 * @property array<string, mixed> $original_snapshot
 */
class ManagementExecutionCorrection extends Model
{
    /** @use HasFactory<ManagementExecutionCorrectionFactory> */
    use HasFactory;

    /** Conserva el instante al guardar fechas con zona horaria en PostgreSQL. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected static function booted(): void
    {
        static::creating(function (self $correction): void {
            $correction->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'original_snapshot' => 'array', 'compensation_snapshot' => 'array'];
    }

    /** @return BelongsTo<Flock, $this> */
    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
