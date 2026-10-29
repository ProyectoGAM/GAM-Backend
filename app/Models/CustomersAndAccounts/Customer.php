<?php

namespace App\Models\CustomersAndAccounts;

use App\Enums\CustomersAndAccounts\CustomerStatus;
use App\Models\Deliveries\DeliveryStop;
use App\Models\Geography\Locality;
use Carbon\CarbonImmutable;
use Database\Factories\CustomersAndAccounts\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $public_id
 * @property string|null $name
 * @property string|null $business_name
 * @property CustomerStatus $status
 * @property int $created_by
 * @property string|null $document_type
 * @property string|null $document_number
 * @property string|null $phone
 * @property string|null $email
 * @property int|null $locality_id
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property string|null $visit_frequency
 * @property string|null $commercial_conditions
 * @property string|null $notes
 * @property string|null $delivery_notes
 * @property CustomerAccount $account
 * @property Locality|null $locality
 * @property DeliveryStop|null $lastDelivery
 * @property bool|null $visited_in_delivery
 * @property bool|null $paid_in_delivery
 * @property bool|null $delivered_in_delivery
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'business_name', 'document_type', 'document_number', 'phone', 'email', 'locality_id', 'address', 'latitude', 'longitude', 'visit_frequency', 'commercial_conditions', 'notes', 'delivery_notes'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'status' => CustomerStatus::class];
    }

    public function displayName(): string
    {
        return $this->name ?? $this->business_name ?? 'Cliente · '.$this->public_id;
    }

    /** @return HasOne<CustomerAccount, $this> */
    public function account(): HasOne
    {
        return $this->hasOne(CustomerAccount::class);
    }

    /** @return BelongsTo<Locality, $this> */
    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }

    /** @return HasMany<DeliveryStop, $this> */
    public function stops(): HasMany
    {
        return $this->hasMany(DeliveryStop::class);
    }

    /** @return HasManyThrough<CustomerAccountMovement, CustomerAccount, $this> */
    public function movements(): HasManyThrough
    {
        return $this->hasManyThrough(CustomerAccountMovement::class, CustomerAccount::class, 'customer_id', 'customer_account_id');
    }

    /** @return HasOne<DeliveryStop, $this> */
    public function lastDelivery(): HasOne
    {
        return $this->hasOne(DeliveryStop::class)->ofMany(['visited_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->where('status', 'delivered'));
    }
}
