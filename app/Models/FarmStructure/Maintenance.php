<?php

namespace App\Models\FarmStructure;

use App\Models\User;
use App\Modules\FarmStructure\Domain\Enums\MaintenanceStatus;
use App\Shared\Money;
use Database\Factories\FarmStructure\MaintenanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $poultry_house_id
 * @property Carbon $maintenance_date
 * @property string $description
 * @property string $cost_amount
 * @property string $cost_currency
 * @property int $responsible_user_id
 * @property string $responsible_name
 * @property int $created_by
 * @property string $idempotency_key
 * @property string $request_hash
 * @property MaintenanceStatus $status
 * @property int $version
 * @property string|null $cancellation_reason
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Money $cost
 * @property-read PoultryHouse $poultryHouse
 * @property-read User $responsibleUser
 */
#[Fillable(['poultry_house_id', 'maintenance_date', 'description', 'cost_amount', 'cost_currency',
    'responsible_user_id', 'responsible_name', 'created_by', 'idempotency_key', 'request_hash',
    'status', 'version', 'cancellation_reason', 'cancelled_at'])]
class Maintenance extends Model
{
    /** @use HasFactory<MaintenanceFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'maintenance_date' => 'date',
            'cost_amount' => 'decimal:4',
            'status' => MaintenanceStatus::class,
            'version' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return Attribute<Money, never> */
    protected function cost(): Attribute
    {
        return Attribute::make(
            get: fn (): Money => Money::fromDecimal($this->cost_amount, $this->cost_currency),
        )->withoutObjectCaching();
    }

    /** @return BelongsTo<PoultryHouse, $this> */
    public function poultryHouse(): BelongsTo
    {
        return $this->belongsTo(PoultryHouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id')->withTrashed();
    }
}
