<?php

namespace App\Models\Inventory;

use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class EggStockTransaction extends Model
{
    protected $table = 'egg_stock_transactions';

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            $transaction->public_id ??= (string) Str::ulid();
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
            'quantity' => 'integer',
            'version' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProductionUnit, $this> */
    public function productionUnit(): BelongsTo
    {
        return $this->belongsTo(ProductionUnit::class);
    }

    /** @return BelongsTo<EggStockAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(EggStockAccount::class, 'egg_stock_account_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<EggStockTransactionRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(EggStockTransactionRevision::class);
    }
}
