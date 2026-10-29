<?php

namespace App\Models\CustomersAndAccounts;

use Carbon\CarbonImmutable;
use Database\Factories\CustomersAndAccounts\CustomerOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $operation_id
 * @property int $created_by
 * @property string $idempotency_key
 * @property string $kind
 * @property string $request_hash
 * @property int|null $customer_id
 * @property int|null $delivery_id
 * @property array<string, mixed> $result
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CustomerOperation extends Model
{
    /** @use HasFactory<CustomerOperationFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['result' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasMany<CustomerAccountMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CustomerAccountMovement::class);
    }
}
