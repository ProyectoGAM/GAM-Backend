<?php

namespace App\Models\CustomersAndAccounts;

use Carbon\CarbonImmutable;
use Database\Factories\CustomersAndAccounts\CustomerAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $balance
 * @property int $version
 * @property Customer $customer
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $updated_at
 */
class CustomerAccount extends Model
{
    /** @use HasFactory<CustomerAccountFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'balance' => 'decimal:3', 'version' => 'integer'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<CustomerAccountMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CustomerAccountMovement::class);
    }
}
