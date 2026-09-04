<?php

namespace App\Modules\Lots\Application\Services;

use App\Modules\Lots\Domain\Exceptions\LotsConflict;

final readonly class EggCollectionRules
{
    /** @param array<string, mixed> $data @return array{quantity:int, occurred_at:string|null, notes:string|null} */
    public function normalize(array $data): array
    {
        $quantity = (int) ($data['quantity'] ?? 0);
        if ($quantity < 1 || $quantity > 2147483647) {
            throw new LotsConflict('La cantidad debe ser un entero entre 1 y 2147483647.');
        }

        return ['quantity' => $quantity, 'occurred_at' => $data['occurred_at'] ?? null, 'notes' => $data['notes'] ?? null];
    }
}
