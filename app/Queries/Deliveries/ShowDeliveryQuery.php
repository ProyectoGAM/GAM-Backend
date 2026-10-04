<?php

namespace App\Queries\Deliveries;

use App\Models\Deliveries\Delivery;

final readonly class ShowDeliveryQuery
{
    public function execute(Delivery $delivery): Delivery
    {
        return Delivery::query()
            ->with([
                'driver:id,name',
                'productionUnit:id,name,latitude,longitude',
                'loads',
                'stops',
                'latestLocation',
                'locations' => fn ($query) => $query->orderBy('captured_at')->limit(500),
            ])
            ->findOrFail($delivery->getKey());
    }
}
