<?php

namespace App\Services\Inventory;

use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\SuppliersAndCatalogs\ProductKind;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\StockBalance;
use App\Models\User;
use App\ValueObjects\Inventory\InventoryQuantity;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class InventoryReversalAvailability
{
    public function __construct(private StockProductCompatibility $compatibility) {}

    public function restriction(InventoryMovement $movement, string $requiredPermission = 'inventory.adjust'): ?string
    {
        if ($movement->reference_type === 'stock_regularization') {
            return 'Una regularización no puede revertirse reintroduciendo stock incompatible.';
        }
        if (in_array($movement->reference_type, ['feed_manufacture', 'feed_manufacture_cancellation', 'feed_ingredient_adjustment'], true)) {
            return 'Los movimientos de alimento deben corregirse desde su operación de elaboración o ajuste.';
        }
        if (in_array($movement->reference_type, ['vaccination_application', 'medicine_application'], true) && $requiredPermission !== 'management-plans.execute') {
            return 'Los consumos sanitarios deben corregirse desde su aplicación.';
        }
        if ($movement->reversal()->exists()) {
            return 'El movimiento ya fue revertido.';
        }

        return null;
    }

    public function currentReason(InventoryMovement $movement, User $viewer): ?string
    {
        if (! $viewer->can('reverse', $movement)) {
            return 'No tienes permiso para revertir movimientos de inventario.';
        }
        $restriction = $this->restriction($movement);
        if ($restriction !== null) {
            return $restriction;
        }
        $movement->loadMissing(['lines.product', 'lines.stockLocation.productionUnit', 'lines.stockLocation.poultryHouse']);
        if ($movement->lines->isEmpty()) {
            return 'El movimiento no conserva líneas que puedan compensarse.';
        }
        $balances = StockBalance::query()->whereIn('product_id', $movement->lines->pluck('product_id'))
            ->whereIn('stock_location_id', $movement->lines->pluck('stock_location_id'))->get()
            ->keyBy(fn (StockBalance $balance): string => $balance->product_id.':'.$balance->stock_location_id);
        foreach ($movement->lines as $line) {
            $product = $line->product;
            $location = $line->stockLocation;
            if ($product === null || $product->status->value !== 'active' || ! $product->stock_tracked) {
                return 'Un producto del movimiento está inactivo o ya no controla stock.';
            }
            if ($product->system_key === 'generic_egg') {
                return 'Los movimientos de huevos deben corregirse desde su operación de origen.';
            }
            if ($location === null || ! $this->compatibility->isOperational($location)) {
                return 'Una ubicación o su unidad productiva no está operativa.';
            }
            if (! $this->compatibility->isCompatible($product, $location)) {
                return 'Un producto ya no es compatible con su ubicación.';
            }
            $balance = $balances->get($line->product_id.':'.$line->stock_location_id);
            $quantity = BigDecimal::of((string) ($balance->on_hand_quantity ?? '0'))->minus((string) $line->on_hand_delta);
            $allowNegative = $location->poultryHouse?->type === PoultryHouseType::Feed && $product->kind === ProductKind::RawMaterial;
            if ($quantity->isNegative() && ! $allowNegative) {
                return 'El stock actual no alcanza para compensar el movimiento.';
            }
            try {
                InventoryQuantity::from((string) $quantity, $product->base_unit);
            } catch (InvalidArgumentException $exception) {
                return $exception->getMessage();
            }
        }

        return null;
    }
}
