<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['operation_id', 'created_by', 'idempotency_key', 'command', 'request_hash', 'result', 'created_at'])]
class EggStockCommand extends Model
{
    public $timestamps = false;

    protected $table = 'egg_stock_commands';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['result' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
