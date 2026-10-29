<?php

namespace App\Services\Deliveries;

use App\Exceptions\Deliveries\DeliveryConflict;

final class LocalDeliveryVehicleReference
{
    public function vehicleReference(): string
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new DeliveryConflict('Los vehículos todavía no están integrados. No se puede iniciar un reparto en este entorno.');
        }

        // TODO(M16): reemplazar por el vehículo disponible asignado desde Vehículos.
        return 'demo-vehicle-001';
    }
}
