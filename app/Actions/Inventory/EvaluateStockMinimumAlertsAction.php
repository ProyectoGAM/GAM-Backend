<?php

namespace App\Actions\Inventory;

use App\Actions\Notifications\SynchronizeOperationalAlertsAction;
use App\Enums\FarmStructure\ProductionUnitStatus;
use App\Enums\Inventory\StockLocationStatus;
use App\Enums\Inventory\StockLocationType;
use App\Enums\Notifications\NotificationUrgency;
use App\Enums\Notifications\OperationalAlertType;
use App\Enums\SuppliersAndCatalogs\ProductStatus;
use App\Interfaces\Clock;
use App\Models\FarmStructure\ProductionUnit;
use App\Services\Inventory\StockProductCompatibility;
use Illuminate\Support\Facades\DB;

final readonly class EvaluateStockMinimumAlertsAction
{
    public function __construct(
        private SynchronizeOperationalAlertsAction $synchronize,
        private StockProductCompatibility $compatibility,
        private Clock $clock,
    ) {}

    public function execute(?int $unitId): void
    {
        DB::transaction(function () use ($unitId): void {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['stock-minimum-alerts:'.($unitId ?? 'global')]);
            $active = $unitId === null || ProductionUnit::query()->whereKey($unitId)->where('status', ProductionUnitStatus::Active)->exists();
            $pending = $active ? $this->pending($unitId) : [];

            $this->synchronize->execute($unitId, OperationalAlertType::StockMinimumReached, 'can_view_inventory', 'stock_balance', $this->clock->now(), $pending);
        }, 3);
    }

    /** @return array<string, array{entity_id: string, target_path: string, urgency: NotificationUrgency, title: string, message: string}> */
    private function pending(?int $unitId): array
    {
        $balances = $this->compatibility->compatibleBalances()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_balances.stock_location_id')
            ->where('stock_locations.production_unit_id', $unitId)
            ->where('stock_locations.status', StockLocationStatus::Active)
            ->where('products.status', ProductStatus::Active)
            ->whereIn('stock_locations.type', [StockLocationType::Warehouse, StockLocationType::FeedPlant])
            ->whereColumn('stock_balances.on_hand_quantity', '<=', 'stock_balances.minimum_quantity')
            ->select('stock_balances.id', 'stock_balances.on_hand_quantity', 'stock_balances.minimum_quantity')
            ->addSelect('products.name as product_name', 'products.base_unit', 'stock_locations.name as location_name');
        $pending = [];

        foreach ($balances->lazyById(100, 'stock_balances.id', 'id') as $balance) {
            $id = (string) $balance->getKey();
            $atMinimum = $balance->on_hand_quantity === $balance->minimum_quantity;
            $quantity = rtrim(rtrim($balance->on_hand_quantity, '0'), '.');
            $minimum = rtrim(rtrim($balance->minimum_quantity, '0'), '.');
            $product = $balance->getAttribute('product_name');
            $location = $balance->getAttribute('location_name');
            $unit = $balance->getAttribute('base_unit');
            $unit = match ($unit) {
                'unit' => 'unid.',
                'dose' => 'dosis',
                default => $unit,
            };

            $pending['stock-minimum:'.$id] = [
                'entity_id' => $id,
                'target_path' => '/administracion/inventario/existencias',
                'urgency' => $atMinimum ? NotificationUrgency::Medium : NotificationUrgency::High,
                'title' => $atMinimum ? 'Stock en el mínimo' : 'Stock por debajo del mínimo',
                'message' => "El stock de «{$product}» en «{$location}» es de {$quantity} {$unit}. El mínimo es de {$minimum} {$unit}.",
            ];
        }

        return $pending;
    }
}
