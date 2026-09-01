<?php

namespace App\Modules\Lots\Application\Services;

use App\Models\SuppliersAndCatalogs\Product;
use App\Modules\Lots\Domain\Exceptions\LotsConflict;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\BaseUnit;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductKind;
use App\Modules\SuppliersAndCatalogs\Domain\Enums\ProductStatus;

final readonly class EggCollectionRules
{
    /**
     * Normaliza la cabecera y las clasificaciones antes de persistirlas.
     *
     * @param  array<string, mixed>  $data
     * @return array{collected_quantity:int, discarded_quantity:int, discard_reason:string|null, lines:list<array{product_id:int, stock_location_id:int, quantity:int}>}
     */
    public function normalize(array $data): array
    {
        $collected = (int) ($data['collected_quantity'] ?? $data['quantity'] ?? 0);
        $discarded = (int) ($data['discarded_quantity'] ?? 0);
        $reason = isset($data['discard_reason']) ? trim((string) $data['discard_reason']) : null;
        $rawLines = $data['lines'] ?? [];

        if ($rawLines === [] && isset($data['product_id'], $data['stock_location_id']) && $collected > $discarded) {
            $rawLines = [[
                'product_id' => $data['product_id'],
                'stock_location_id' => $data['stock_location_id'],
                'quantity' => $collected - $discarded,
            ]];
        }
        if (! is_array($rawLines)) {
            throw new LotsConflict('Las líneas de clasificación no son válidas.');
        }
        if ($collected < 1 || $discarded < 0 || $discarded > $collected) {
            throw new LotsConflict('La cantidad recolectada y el descarte deben formar un total válido.');
        }
        if ($discarded > 0 && ($reason === null || $reason === '')) {
            throw new LotsConflict('Debes indicar el motivo del descarte.');
        }

        $lines = [];
        $keys = [];
        foreach ($rawLines as $line) {
            if (! is_array($line)) {
                throw new LotsConflict('Cada línea de clasificación debe ser un objeto.');
            }
            $productId = (int) ($line['product_id'] ?? 0);
            $locationId = (int) ($line['stock_location_id'] ?? 0);
            $quantity = (int) ($line['quantity'] ?? 0);
            if ($productId < 1 || $locationId < 1 || $quantity < 1) {
                throw new LotsConflict('Cada línea debe indicar producto, ubicación y una cantidad positiva.');
            }
            $key = $productId.':'.$locationId;
            if (isset($keys[$key])) {
                throw new LotsConflict('No puedes repetir el mismo producto y ubicación en una recolección.');
            }
            $keys[$key] = true;
            $lines[] = ['product_id' => $productId, 'stock_location_id' => $locationId, 'quantity' => $quantity];
        }

        usort($lines, static fn (array $left, array $right): int => [$left['stock_location_id'], $left['product_id']] <=> [$right['stock_location_id'], $right['product_id']]);
        $usable = array_sum(array_column($lines, 'quantity'));
        if ($usable !== $collected - $discarded) {
            throw new LotsConflict('La suma de las clasificaciones debe coincidir con la recolección menos el descarte.');
        }
        if ($usable === 0 && $discarded !== $collected) {
            throw new LotsConflict('Debes clasificar los huevos que no fueron descartados.');
        }

        $this->validateProducts($lines);

        return [
            'collected_quantity' => $collected,
            'discarded_quantity' => $discarded,
            'discard_reason' => $discarded === 0 ? null : $reason,
            'lines' => $lines,
        ];
    }

    /** @param list<array{product_id:int, stock_location_id:int, quantity:int}> $lines */
    private function validateProducts(array $lines): void
    {
        if ($lines === []) {
            return;
        }
        $products = Product::query()->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');
        foreach ($lines as $line) {
            $product = $products->get($line['product_id']);
            if ($product === null || $product->getRawOriginal('status') !== ProductStatus::Active->value || $product->getRawOriginal('kind') !== ProductKind::Egg->value || $product->getRawOriginal('base_unit') !== BaseUnit::Unit->value || ! $product->stock_tracked) {
                throw new LotsConflict('Cada clasificación requiere un producto de huevos activo, inventariable y medido en unidades.');
            }
        }
    }
}
