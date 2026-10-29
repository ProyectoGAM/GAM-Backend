<?php

namespace App\Models\CustomersAndAccounts;

use App\Enums\CustomersAndAccounts\CustomerMovementType;
use App\Models\Deliveries\Delivery;
use Carbon\CarbonImmutable;
use Database\Factories\CustomersAndAccounts\CustomerAccountMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $public_id
 * @property CustomerMovementType $type
 * @property int $sequence
 * @property string $amount
 * @property string $balance_before
 * @property string $balance_after
 * @property string $currency
 * @property int $created_by
 * @property string $actor_name
 * @property string $customer_name
 * @property string|null $reason
 * @property string|null $payment_method
 * @property string|null $payment_reference
 * @property int|null $delivery_id
 * @property int|null $delivery_stop_id
 * @property int|null $reverses_movement_id
 * @property CustomerOperation $operation
 * @property CustomerAccount $account
 * @property CustomerAccountMovement|null $reversal
 * @property CustomerAccountMovement|null $original
 * @property Delivery|null $delivery
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CustomerAccountMovement extends Model
{
    /** @use HasFactory<CustomerAccountMovementFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['type' => CustomerMovementType::class, 'amount' => 'decimal:3', 'balance_before' => 'decimal:3', 'balance_after' => 'decimal:3',
            'sequence' => 'integer', 'occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

    /** @return BelongsTo<CustomerOperation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(CustomerOperation::class, 'customer_operation_id');
    }

    /** @return HasOne<CustomerAccountMovement, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_movement_id');
    }

    /** @return BelongsTo<CustomerAccountMovement, $this> */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }
}
