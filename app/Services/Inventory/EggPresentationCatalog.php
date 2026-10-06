<?php

namespace App\Services\Inventory;

use App\Enums\Deliveries\DeliveryStatus;
use App\Exceptions\Inventory\InventoryConflict;
use App\Models\Deliveries\Delivery;
use App\Models\Inventory\EggPresentation;

final class EggPresentationCatalog
{
    /** Serializa comienzos y escrituras del catálogo dentro de sus transacciones. */
    public function lock(): void
    {
        // La fila inicial permanece: el catálogo no expone eliminación de presentaciones.
        EggPresentation::query()->orderBy('id')->lockForUpdate()->firstOrFail();
    }

    public function isLocked(): bool
    {
        return Delivery::query()->where('status', DeliveryStatus::Active)->exists();
    }

    public function assertEditable(): void
    {
        $this->lock();
        if ($this->isLocked()) {
            throw new InventoryConflict('El catálogo de presentaciones está bloqueado mientras haya repartos activos.');
        }
    }
}
