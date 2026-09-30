<?php

namespace App\Actions\Deliveries;

use App\Enums\Deliveries\DeliveryStatus;
use App\Exceptions\Deliveries\DeliveryConflict;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryLocationPoint;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class PublishDeliveryLocationsAction
{
    /** @param list<array{client_event_id:string,latitude:float|int,longitude:float|int,accuracy?:float|int|null,speed?:float|int|null,captured_at:string}> $locations */
    public function execute(Delivery $delivery, User $actor, array $locations): array
    {
        return DB::transaction(function () use ($delivery, $actor, $locations): array {
            $lockedDelivery = Delivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedDelivery->driver_id !== $actor->getKey()) {
                throw new DeliveryConflict('No puedes publicar ubicaciones de otro repartidor.');
            }
            if ($lockedDelivery->status !== DeliveryStatus::Active) {
                throw new DeliveryConflict('El reparto ya está cerrado.');
            }

            $accepted = 0;
            $duplicates = 0;
            foreach ($locations as $location) {
                $hash = hash('sha256', json_encode($location, JSON_THROW_ON_ERROR));
                $existing = DeliveryLocationPoint::query()
                    ->where('delivery_id', $lockedDelivery->getKey())
                    ->where('client_event_id', $location['client_event_id'])
                    ->first();
                if ($existing !== null) {
                    if ($existing->request_hash !== $hash) {
                        throw new DeliveryConflict('Una ubicación ya fue recibida con otros datos.');
                    }
                    $duplicates++;

                    continue;
                }

                DeliveryLocationPoint::query()->create([
                    'delivery_id' => $lockedDelivery->getKey(),
                    'client_event_id' => $location['client_event_id'],
                    'latitude' => $location['latitude'],
                    'longitude' => $location['longitude'],
                    'accuracy' => $location['accuracy'] ?? null,
                    'speed' => $location['speed'] ?? null,
                    'captured_at' => $location['captured_at'],
                    'received_at' => now(),
                    'created_at' => now(),
                    'request_hash' => $hash,
                ]);
                $accepted++;
            }

            return ['accepted' => $accepted, 'duplicates' => $duplicates, 'pending' => 0];
        }, 3);
    }
}
