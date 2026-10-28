<?php

namespace App\Actions\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Inventory\StockLocationType;
use App\Exceptions\Inventory\InventoryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\Inventory\StockLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class EnsureHealthStockLocationAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    public function execute(ProductionUnit $unit, User $actor): StockLocation
    {
        // Futuro: la implementación queda disponible sin aprovisionar ubicaciones en esta entrega.
        if (! config('inventory.health_stock_enabled')) {
            throw new InventoryConflict('El stock físico de sanidad está deshabilitado.');
        }

        return DB::transaction(function () use ($unit, $actor): StockLocation {
            ProductionUnit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();
            $location = StockLocation::query()->where('type', StockLocationType::Health)->where('production_unit_id', $unit->getKey())->first();
            if ($location !== null) {
                return $location;
            }
            $location = new StockLocation;
            $location->forceFill([
                'name' => 'Sanidad - UP '.$unit->getKey(),
                'production_unit_id' => $unit->getKey(),
                'type' => StockLocationType::Health,
                'status' => 'active',
                'system_managed' => true,
            ])->save();
            $snapshot = $location->only(['id', 'name', 'production_unit_id', 'type', 'status']);
            $this->auditRecorder->record(AuditEntryData::forSubject(
                subject: $location, actor: $actor, logName: 'inventory', event: 'health_stock_location_created',
                description: 'Ubicación de sanidad de la UP creada', upId: (int) $unit->getKey(),
                properties: ['subject_snapshot' => $snapshot], attributeChanges: ['old' => [], 'new' => $snapshot],
            ));

            return $location;
        }, 3);
    }
}
