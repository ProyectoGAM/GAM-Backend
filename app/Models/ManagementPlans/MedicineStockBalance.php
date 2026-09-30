<?php

namespace App\Models\ManagementPlans;

use App\Models\SuppliersAndCatalogs\Medicine;
use Database\Factories\ManagementPlans\MedicineStockBalanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property int $on_hand_quantity @property int $version */
class MedicineStockBalance extends Model
{
    /** @use HasFactory<MedicineStockBalanceFactory> */
    use HasFactory;

    protected $fillable = ['medicine_id', 'on_hand_quantity', 'version'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['on_hand_quantity' => 'integer', 'version' => 'integer'];
    }

    /** @return BelongsTo<Medicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
