<?php

namespace App\Actions\Inventory;

use App\Actions\SuppliersAndCatalogs\ConfigureInternalSupplierAction;
use App\Actions\SuppliersAndCatalogs\EnsureMedicineProductAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\Inventory\StockLocationType;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\EggStockAccount;
use App\Models\Inventory\StockLocation;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;
use Illuminate\Support\Facades\DB;

final readonly class PrepareStockStorageAction
{
    public function __construct(
        private AuditRecorder $auditRecorder,
        private EnsureHealthStockLocationAction $healthLocations,
        private EnsureMedicineProductAction $medicineProducts,
        private ConfigureInternalSupplierAction $internalSupplier,
    ) {}

    public function execute(User $actor): void
    {
        LotsAuthorization::ensure($actor, 'inventory.manage');
        StockLocation::query()->where('type', StockLocationType::Unknown)->orderBy('id')->eachById(function (StockLocation $location) use ($actor): void {
            DB::transaction(function () use ($location, $actor): void {
                $locked = StockLocation::query()->whereKey($location->getKey())->lockForUpdate()->firstOrFail();
                if ($locked->type !== StockLocationType::Unknown) {
                    return;
                }
                $type = match (true) {
                    $locked->poultry_house_id !== null && $locked->poultryHouse?->type === PoultryHouseType::Feed
                        && (int) $locked->production_unit_id === (int) $locked->poultryHouse->production_unit_id => StockLocationType::FeedPlant,
                    $locked->poultry_house_id === null && EggStockAccount::query()->where('stock_location_id', $locked->getKey())->where('production_unit_id', $locked->production_unit_id)->exists() => StockLocationType::EggAccount,
                    $locked->poultry_house_id === null && ! $locked->system_managed => StockLocationType::Warehouse,
                    default => StockLocationType::Unknown,
                };
                if ($type === StockLocationType::Unknown) {
                    return;
                }
                $locked->forceFill(['type' => $type])->save();
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $locked, actor: $actor, logName: 'inventory', event: 'stock_location_classified',
                    description: 'Ubicación histórica clasificada', upId: $locked->production_unit_id,
                    properties: ['subject_snapshot' => $locked->only(['id', 'name', 'type', 'production_unit_id', 'poultry_house_id'])],
                    attributeChanges: ['old' => ['type' => 'unknown'], 'new' => ['type' => $type->value]],
                ));
            }, 3);
        });
        ProductionUnit::query()->orderBy('id')->eachById(fn (ProductionUnit $unit): StockLocation => $this->healthLocations->execute($unit, $actor));
        Medicine::query()->whereNull('product_id')->orderBy('id')->eachById(fn (Medicine $medicine) => $this->medicineProducts->execute($medicine, $actor));
        $this->internalSupplier->execute($actor);
    }
}
