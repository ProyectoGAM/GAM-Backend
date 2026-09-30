<?php

namespace App\Models\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\SuppliersAndCatalogs\Product;
use App\Models\User;
use Database\Factories\ManagementPlans\RationChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** @property string $public_id @property string $operation_id @property array<string, mixed>|null $finished_feed_product_snapshot */
class RationChange extends Model
{
    /** @use HasFactory<RationChangeFactory> */
    use HasFactory;

    /** Conserva el instante al guardar fechas con zona horaria en PostgreSQL. */
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected static function booted(): void
    {
        static::creating(function (self $change): void {
            $change->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'finished_feed_product_snapshot' => 'array'];
    }

    /** @return BelongsTo<Flock, $this> */
    public function flock(): BelongsTo
    {
        return $this->belongsTo(Flock::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function finishedFeedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'finished_feed_product_id');
    }

    /** @return BelongsTo<User, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
