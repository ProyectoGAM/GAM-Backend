<?php

namespace App\Actions\Deliveries;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Deliveries\DeliveryStatus;
use App\Enums\Deliveries\DeliveryStopStatus;
use App\Exceptions\Deliveries\DeliveryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryStop;
use App\Models\User;
use App\Services\Deliveries\DeliveryPricing;
use App\Services\Deliveries\DeliveryUnitBalance;
use App\Services\Deliveries\LocalDeliveryClientCatalog;
use App\ValueObjects\Money;
use Illuminate\Support\Facades\DB;

final readonly class RecordDeliveryStopAction
{
    public function __construct(
        private AuditRecorder $audit,
        private LocalDeliveryClientCatalog $clients,
        private DeliveryUnitBalance $balances,
        private DeliveryPricing $pricing,
    ) {}

    /** @param array{client_reference:string,status:string,items?:array,visit_reason?:string|null,notes?:string|null} $data */
    public function execute(Delivery $delivery, User $actor, array $data, string $idempotencyKey): DeliveryStop
    {
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($delivery, $actor, $data, $idempotencyKey, $requestHash): DeliveryStop {
            $lockedDelivery = Delivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedDelivery->driver_id !== $actor->getKey()) {
                throw new DeliveryConflict('No puedes registrar entregas de otro repartidor.');
            }
            $replayed = DeliveryStop::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($replayed !== null) {
                if ($replayed->delivery_id !== $lockedDelivery->getKey() || $replayed->request_hash !== $requestHash) {
                    throw new DeliveryConflict('La clave de idempotencia ya fue utilizada con otros datos.');
                }

                return $replayed;
            }

            if ($lockedDelivery->status !== DeliveryStatus::Active) {
                throw new DeliveryConflict('El reparto ya está cerrado.');
            }

            $stop = DeliveryStop::query()
                ->where('delivery_id', $lockedDelivery->getKey())
                ->where('client_reference', $data['client_reference'])
                ->lockForUpdate()
                ->first();
            if ($stop !== null && $stop->status !== DeliveryStopStatus::Pending) {
                throw new DeliveryConflict('La visita de este cliente ya fue registrada.');
            }
            $client = $stop === null ? $this->clients->find($data['client_reference']) : null;

            $allocated = $data['status'] === DeliveryStopStatus::Delivered->value
                ? $this->balances->allocate($lockedDelivery->load(['loads', 'stops']), $data['items'])
                : null;
            $delivered = DeliveryStop::query()
                ->where('delivery_id', $lockedDelivery->getKey())
                ->where('status', DeliveryStopStatus::Delivered)
                ->sum('delivered_quantity');
            $quantity = $allocated['quantity'] ?? 0;
            if ($data['status'] === DeliveryStopStatus::Delivered->value && $quantity < 1) {
                throw new DeliveryConflict('La entrega debe incluir al menos un huevo.');
            }
            if ($data['status'] === DeliveryStopStatus::Delivered->value
                && $delivered + $quantity > $lockedDelivery->loaded_quantity) {
                throw new DeliveryConflict('La cantidad entregada supera la carga disponible.');
            }

            $priced = $allocated === null ? ['items' => [], 'total_amount' => '0.000']
                : $this->pricing->calculate($allocated['items'], $data['items']);

            $attributes = [
                'status' => $data['status'],
                'delivered_quantity' => $quantity,
                'items' => $priced['items'],
                'total_amount' => $priced['total_amount'],
                'visit_reason' => $data['visit_reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'visited_at' => now(),
            ];
            if ($stop === null) {
                // sequence conserva el orden efectivo de visitas, nunca una ruta asignada.
                $stop = DeliveryStop::query()->create([
                    'delivery_id' => $lockedDelivery->getKey(),
                    'client_reference' => $client['client_reference'],
                    'client_name' => $client['client_name'],
                    'address' => $client['address'],
                    'latitude' => $client['latitude'],
                    'longitude' => $client['longitude'],
                    'sequence' => (int) DeliveryStop::query()->where('delivery_id', $lockedDelivery->getKey())->max('sequence') + 1,
                    ...$attributes,
                ]);
            } else {
                $stop->forceFill($attributes)->save();
            }

            // TODO(M14/M15): convertir esta entrega en la operación autoritativa de venta/cuenta corriente.
            $this->audit->record(AuditEntryData::forSubject(
                subject: $stop,
                actor: $actor,
                logName: 'deliveries',
                event: 'delivery_stop_recorded',
                description: 'Visita de reparto registrada',
                operationId: $idempotencyKey,
                source: 'delivery',
                properties: [
                    'delivery_id' => $lockedDelivery->public_id,
                    'client_reference' => $stop->client_reference,
                    'status' => $stop->status->value,
                    'delivered_quantity' => $quantity,
                    'items' => $priced['items'],
                    'total_amount' => $priced['total_amount'],
                    'currency' => Money::CURRENCY,
                    'result' => 'success',
                ],
            ));

            return $stop->fresh();
        }, 3);
    }
}
