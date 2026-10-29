<?php

namespace App\Actions\Deliveries;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\CustomersAndAccounts\CustomerStatus;
use App\Enums\Deliveries\DeliveryStatus;
use App\Enums\Deliveries\DeliveryStopStatus;
use App\Exceptions\CustomersAndAccounts\CustomerAccountConflict;
use App\Exceptions\Deliveries\DeliveryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryStop;
use App\Models\User;
use App\Services\CustomersAndAccounts\CustomerAccountLedger;
use App\Services\CustomersAndAccounts\CustomerAmounts;
use App\Services\CustomersAndAccounts\CustomerOperationRunner;
use App\Services\Deliveries\DeliveryPricing;
use App\Services\Deliveries\DeliveryUnitBalance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class RecordDeliveryStopAction
{
    public function __construct(private AuditRecorder $audit, private DeliveryUnitBalance $balances,
        private DeliveryPricing $pricing, private CustomerOperationRunner $operations, private CustomerAccountLedger $ledger) {}

    public function execute(Delivery $delivery, User $actor, array $data, string $idempotencyKey): DeliveryStop
    {
        // El hash original permite reconocer colas históricas confirmadas sin inventarles deuda.
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $fingerprint = $data;
        unset($fingerprint['idempotency_key']);
        if (isset($fingerprint['client_event_id'])) {
            $fingerprint['client_event_id'] = strtolower($fingerprint['client_event_id']);
        }
        if (isset($fingerprint['payment']['amount'])) {
            $fingerprint['payment']['amount'] = CustomerAmounts::decimal(CustomerAmounts::parse($fingerprint['payment']['amount'], allowZero: true));
        }

        return DB::transaction(function () use ($delivery, $actor, $data, $idempotencyKey, $requestHash, $fingerprint): DeliveryStop {
            $actor = User::query()->whereKey($actor->id)->lockForUpdate()->first();
            if ($actor === null || ! $actor->can('delivery.update-own')) {
                throw new AuthorizationException;
            }
            $lockedDelivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($lockedDelivery->driver_id !== $actor->id) {
                throw new DeliveryConflict('No puedes registrar entregas de otro repartidor.');
            }
            $replayed = DeliveryStop::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($replayed !== null) {
                if ($replayed->delivery_id !== $lockedDelivery->id || ($replayed->accounting_status === 'legacy_unreconciled' && $replayed->request_hash !== $requestHash)) {
                    throw new DeliveryConflict('La clave de idempotencia ya fue utilizada con otros datos.');
                }

                if ($replayed->accounting_status !== 'legacy_unreconciled') {
                    $this->operations->run('delivery_stop', $idempotencyKey,
                        ['delivery' => $lockedDelivery->public_id, 'data' => $fingerprint], $actor, 'delivery.update-own',
                        fn () => throw new CustomerAccountConflict('No se encontró la operación contable original de esta entrega.'));
                }

                return $replayed->load('accountMovements.account.customer', 'accountMovements.operation', 'accountMovements.delivery', 'accountMovements.original', 'accountMovements.reversal');
            }
            if ($lockedDelivery->status !== DeliveryStatus::Active) {
                throw new DeliveryConflict('El reparto ya está cerrado.');
            }
            $isDelivered = ($data['status'] ?? null) === DeliveryStopStatus::Delivered->value;
            if ($isDelivered && (($data['accounting_version'] ?? null) !== 2 || ! isset($data['payment']['amount'])
                || strtolower($data['client_event_id'] ?? '') !== strtolower($idempotencyKey))) {
                throw new CustomerAccountConflict('Esta entrega requiere revisión: indicá el importe cobrado o confirmá que fue fiada antes de sincronizar.');
            }
            if ($isDelivered) {
                $paid = CustomerAmounts::parse($data['payment']['amount'], allowZero: true);
                if (! $paid->isZero()) {
                    Validator::make($data['payment'], ['payment_method' => ['required', 'in:cash,transfer,other'],
                        'payment_reference' => ['sometimes', 'nullable', 'string', 'max:240'], 'reason' => ['sometimes', 'nullable', 'string', 'max:2000']])->validate();
                }
            }
            $operation = $this->operations->run('delivery_stop', $idempotencyKey,
                ['delivery' => $lockedDelivery->public_id, 'data' => $fingerprint], $actor, 'delivery.update-own',
                function (CustomerOperation $operation, User $actor) use ($lockedDelivery, $data, $requestHash, $idempotencyKey, $isDelivered): array {
                    $customer = Customer::query()->where('public_id', $data['client_reference'])->lockForUpdate()->first();
                    if ($customer === null) {
                        throw new DeliveryConflict('El cliente indicado no está disponible.');
                    }
                    if ($customer->status !== CustomerStatus::Active || $customer->latitude === null || $customer->longitude === null || ! filled($customer->address)) {
                        throw new DeliveryConflict('El cliente debe estar activo y tener dirección y ubicación para registrar una entrega o visita. Podés registrar un cobro sin mercadería.');
                    }
                    $stop = DeliveryStop::query()->where('delivery_id', $lockedDelivery->id)
                        ->where(fn ($q) => $q->where('customer_id', $customer->id)->orWhere('client_reference', $data['client_reference']))
                        ->lockForUpdate()->first();
                    if ($stop !== null && $stop->status !== DeliveryStopStatus::Pending) {
                        throw new DeliveryConflict('La visita de este cliente ya fue registrada.');
                    }
                    $allocated = $isDelivered ? $this->balances->allocate($lockedDelivery->load(['loads', 'stops']), $data['items']) : null;
                    $quantity = $allocated['quantity'] ?? 0;
                    $delivered = (int) DeliveryStop::query()->where('delivery_id', $lockedDelivery->id)->where('status', DeliveryStopStatus::Delivered)->sum('delivered_quantity');
                    if ($isDelivered && ($quantity < 1 || $delivered + $quantity > $lockedDelivery->loaded_quantity)) {
                        throw new DeliveryConflict('La cantidad entregada debe ser positiva y no superar la carga disponible.');
                    }
                    $priced = $allocated === null ? ['items' => [], 'total_amount' => '0.000'] : $this->pricing->calculate($allocated['items'], $data['items']);
                    $attributes = ['status' => $data['status'], 'customer_id' => $customer->id,
                        'accounting_status' => $isDelivered ? 'posted' : 'not_applicable', 'delivered_quantity' => $quantity,
                        'items' => $priced['items'], 'total_amount' => $priced['total_amount'], 'visit_reason' => $data['visit_reason'] ?? null,
                        'notes' => $data['notes'] ?? null, 'idempotency_key' => $idempotencyKey, 'request_hash' => $requestHash, 'visited_at' => now()];
                    if ($stop === null) {
                        $stop = DeliveryStop::query()->create(['delivery_id' => $lockedDelivery->id,
                            'client_reference' => $customer->public_id, 'client_name' => $customer->displayName(), 'address' => $customer->address,
                            'latitude' => $customer->latitude, 'longitude' => $customer->longitude,
                            'sequence' => (int) DeliveryStop::query()->where('delivery_id', $lockedDelivery->id)->max('sequence') + 1, ...$attributes]);
                    } else {
                        $stop->forceFill($attributes)->save();
                    }
                    $operation->customer_id = $customer->id;
                    $operation->delivery_id = $lockedDelivery->id;
                    if ($isDelivered) {
                        if (! CustomerAmounts::parse($priced['total_amount'], allowZero: true)->isZero()) {
                            $this->ledger->post($customer, $operation, $actor, 'sale_charge', $priced['total_amount'], ['delivery_stop_id' => $stop->id]);
                        }
                        $payment = $data['payment'];
                        $amount = CustomerAmounts::parse($payment['amount'], allowZero: true);
                        if (! $amount->isZero()) {
                            $this->ledger->post($customer, $operation, $actor, 'payment', CustomerAmounts::decimal($amount->negated()),
                                ['delivery_stop_id' => $stop->id, 'payment_method' => $payment['payment_method'],
                                    'payment_reference' => $payment['payment_reference'] ?? null, 'reason' => $payment['reason'] ?? null]);
                        }
                    }
                    $this->audit->record(AuditEntryData::forSubject(subject: $stop, actor: $actor, logName: 'deliveries',
                        event: 'delivery_stop_recorded', description: 'Visita de reparto registrada', operationId: $operation->operation_id, source: 'delivery',
                        properties: ['delivery_id' => $lockedDelivery->public_id, 'client_reference' => $stop->client_reference,
                            'status' => $stop->status->value, 'delivered_quantity' => $quantity, 'items' => $priced['items'],
                            'total_amount' => $priced['total_amount'], 'currency' => 'UYU', 'result' => 'success']));

                    return ['stop_id' => $stop->id];
                });

            return DeliveryStop::query()->with(['accountMovements.account.customer', 'accountMovements.operation', 'accountMovements.delivery', 'accountMovements.original', 'accountMovements.reversal'])
                ->findOrFail($operation->result['stop_id']);
        }, 3);
    }
}
