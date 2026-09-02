<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['public_id', 'egg_stock_transaction_id', 'operation_id', 'action', 'before', 'after', 'correction_reason', 'created_by', 'created_at'])]
class EggStockTransactionRevision extends Model
{
    public $timestamps = false;

    protected $table = 'egg_stock_transaction_revisions';

    protected $dateFormat = 'Y-m-d H:i:sP';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<EggStockTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(EggStockTransaction::class, 'egg_stock_transaction_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
