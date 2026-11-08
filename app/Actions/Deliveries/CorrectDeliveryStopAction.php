<?php

namespace App\Actions\Deliveries;

use App\Actions\Inventory\RecordEggStockTransactionAction;
use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\Deliveries\DeliveryStatus;
use App\Enums\Deliveries\DeliveryStopStatus;
use App\Exceptions\Deliveries\DeliveryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\CustomersAndAccounts\Customer;
use App\Models\CustomersAndAccounts\CustomerOperation;
use App\Models\Deliveries\Delivery;
use App\Models\Deliveries\DeliveryStop;
use App\Models\FarmStructure\ProductionUnit;
use App\Models\User;
use App\Services\CustomersAndAccounts\CustomerAccountLedger;
use App\Services\CustomersAndAccounts\CustomerAmounts;
use App\Services\CustomersAndAccounts\CustomerOperationRunner;
use App\Services\Deliveries\DeliveryEggAlerts;
use App\Services\Deliveries\DeliveryPricing;
use App\Services\Deliveries\DeliveryUnitBalance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final readonly class CorrectDeliveryStopAction
{
    public function __construct(
        private CustomerOperationRunner $operations,
        private CustomerAccountLedger $ledger,
        private DeliveryPricing $pricing,
        private DeliveryUnitBalance $balances,
        private RecordEggStockTransactionAction $stock,
        private DeliveryEggAlerts $eggAlerts,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(Delivery $delivery, int $stopId, User $actor, array $data, string $key): CustomerOperation
    {
        if ($actor->usesDevicePin()) {
            throw new AuthorizationException;
        }
        unset($data['idempotency_key']);
        $data['motivo'] = trim($data['motivo']);
        $data['version'] = (int) $data['version'];

        return $this->operations->run(CustomerOperation::DELIVERY_STOP_CORRECTION, $key, ['delivery' => $delivery->public_id, 'stop' => $stopId, 'data' => $data], $actor, 'adjust',
            function (CustomerOperation $operation, User $actor) use ($delivery, $stopId, $data): array {
                $delivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                $stop = $delivery->stops()->whereKey($stopId)->lockForUpdate()->firstOrFail();
                if ($delivery->status === DeliveryStatus::Cancelled || $stop->status !== DeliveryStopStatus::Delivered
                    || $stop->version !== $data['version']) {
                    throw new DeliveryConflict('La entrega no está disponible o cambió de versión.');
                }

                $delivered = (int) $delivery->stops()->where('status', DeliveryStopStatus::Delivered)->sum('delivered_quantity');
                $beforeBalance = $delivery->loaded_quantity - $delivered - $delivery->returned_quantity;
                if ($beforeBalance >= 0) {
                    throw new DeliveryConflict('El reparto no tiene un saldo negativo que rectificar.');
                }
                $before = $this->snapshot($delivery, $stop, $beforeBalance);
                $corrected = $this->correctedDelivery($stop, $data);
                if ($corrected['quantity'] >= $stop->delivered_quantity) {
                    throw ValidationException::withMessages(['cantidad_entregada' => 'La rectificación debe reducir los huevos registrados en la entrega.']);
                }

                $operation->customer_id = $stop->customer_id;
                $operation->delivery_id = $delivery->id;
                $credit = $this->compensateCharge($operation, $actor, $stop, $corrected['total_amount'], $data['motivo']);
                $balance = $beforeBalance + $stop->delivered_quantity - $corrected['quantity'];
                $returned = $this->recordClosedSurplus($delivery, $actor, $operation, $balance, isset($data['return_production_unit_id']) ? (int) $data['return_production_unit_id'] : null);
                $stop->forceFill([
                    'delivered_quantity' => $corrected['quantity'], 'items' => $corrected['items'],
                    'total_amount' => $corrected['total_amount'], 'version' => $stop->version + 1,
                ])->save();
                $after = $this->snapshot($delivery, $stop, $balance - $returned);
                $result = [
                    'operation_id' => $operation->operation_id,
                    'entrega_id' => $stop->id,
                    'motivo' => $data['motivo'],
                    'antes' => $before,
                    'despues' => $after,
                    'movimiento_cuenta_id' => $credit,
                    'registrada_en' => $operation->created_at->toIso8601String(),
                ];
                $this->audit->record(AuditEntryData::forSubject(
                    subject: $stop, actor: $actor, logName: 'deliveries', event: 'delivery_stop_corrected',
                    description: 'Entrega rectificada para regularizar el saldo del reparto', operationId: $operation->operation_id,
                    upId: $delivery->production_unit_id, source: 'delivery',
                    properties: ['delivery_id' => $delivery->public_id, 'reason' => $data['motivo'],
                        'account_movement_id' => $credit, 'returned_quantity' => $returned, 'result' => 'success'],
                    attributeChanges: ['old' => $before, 'new' => $after],
                ));
                $this->eggAlerts->negativeDeliveryBalance($delivery, $balance - $returned);

                return $result;
            });
    }

    /** @param array<string, mixed> $data
     * @return array{quantity: int, items: list<array<string, mixed>>|null, total_amount: string|null}
     */
    private function correctedDelivery(DeliveryStop $stop, array $data): array
    {
        if ($stop->items !== null && $stop->items !== []) {
            if (! isset($data['lineas'])) {
                throw ValidationException::withMessages(['lineas' => 'Rectificá las líneas originales de esta entrega.']);
            }

            return $this->pricing->reduce($stop->items, $data['lineas']);
        }
        if ($stop->accounting_status === 'posted') {
            throw new DeliveryConflict('No se pudo verificar el desglose del cargo original de la entrega.');
        }
        if (! isset($data['cantidad_entregada'])) {
            throw ValidationException::withMessages(['cantidad_entregada' => 'Indicá la cantidad total para esta entrega histórica sin líneas.']);
        }
        $quantity = (int) $data['cantidad_entregada'];
        if ($quantity === 0) {
            return ['quantity' => 0, 'items' => null, 'total_amount' => '0.000'];
        }
        if (! isset($data['importe']) && $stop->total_amount !== null) {
            throw ValidationException::withMessages(['importe' => 'Indicá el importe rectificado de esta entrega histórica.']);
        }

        $amount = isset($data['importe']) ? CustomerAmounts::parse($data['importe'], allowZero: true) : null;
        if ($amount !== null && $stop->total_amount !== null && $amount->isGreaterThan($stop->total_amount)) {
            throw ValidationException::withMessages(['importe' => 'El importe rectificado no puede superar el importe original.']);
        }

        return ['quantity' => $quantity, 'items' => null, 'total_amount' => $amount === null ? null : CustomerAmounts::decimal($amount)];
    }

    private function compensateCharge(CustomerOperation $operation, User $actor, DeliveryStop $stop, ?string $amount, string $reason): ?string
    {
        if ($stop->accounting_status !== 'posted') {
            return null;
        }
        if ($stop->customer_id === null || $stop->total_amount === null || $amount === null) {
            throw new DeliveryConflict('No se pudo verificar el cargo original de la entrega.');
        }

        $before = CustomerAmounts::parse($stop->total_amount, allowZero: true);
        $difference = CustomerAmounts::parse($amount, allowZero: true)->minus($before);
        if ($difference->isZero()) {
            return null;
        }
        if ($difference->isPositive()) {
            throw new DeliveryConflict('La rectificación no puede aumentar el cargo al cliente.');
        }
        $customer = Customer::query()->whereKey($stop->customer_id)->lockForUpdate()->firstOrFail();
        $account = $this->ledger->account($customer);
        $charged = $stop->accountMovements()->where('customer_account_id', $account->id)
            ->whereIn('type', ['sale_charge', 'adjustment_decrease'])->sum('amount');
        if (! $before->isEqualTo((string) $charged)) {
            throw new DeliveryConflict('El cargo registrado no coincide con el importe vigente de la entrega.');
        }

        return $this->ledger->post($customer, $operation, $actor, 'adjustment_decrease', CustomerAmounts::decimal($difference), [
            'delivery_stop_id' => $stop->id, 'reason' => $reason,
        ])->public_id;
    }

    private function recordClosedSurplus(Delivery $delivery, User $actor, CustomerOperation $operation, int $balance, ?int $unitId): int
    {
        if ($delivery->status !== DeliveryStatus::Completed || $balance <= 0) {
            if ($unitId !== null) {
                throw ValidationException::withMessages(['return_production_unit_id' => 'La UP de devolución corresponde a un sobrante de un reparto ya cerrado.']);
            }

            return 0;
        }
        if ($unitId === null || ($delivery->return_production_unit_id !== null && $delivery->return_production_unit_id !== $unitId)
            || ! $this->balances->canReturnTo($delivery, $unitId)) {
            throw ValidationException::withMessages(['return_production_unit_id' => 'Confirmá el destino del sobrante. Si ya hubo una devolución, debe conservarse la misma UP.']);
        }

        $unit = ProductionUnit::query()->whereKey($unitId)->lockForUpdate()->firstOrFail();
        $this->stock->execute(
            unit: $unit, type: 'distribution_return', quantity: $balance, operationId: $operation->operation_id, actor: $actor,
            reason: 'Devolución por rectificación del reparto '.$delivery->public_id, referenceType: 'delivery', referenceId: $delivery->public_id, source: 'delivery',
        );
        $delivery->forceFill(['returned_quantity' => $delivery->returned_quantity + $balance, 'return_production_unit_id' => $unitId])->save();
        $this->eggAlerts->surplus($delivery, $unit, $balance, $operation->operation_id);

        return $balance;
    }

    /** @return array<string, mixed> */
    private function snapshot(Delivery $delivery, DeliveryStop $stop, int $balance): array
    {
        return [
            'version' => $stop->version, 'cantidad_entregada' => $stop->delivered_quantity, 'items' => $stop->items,
            'importe' => $stop->total_amount, 'huevos_devueltos' => $delivery->returned_quantity,
            'return_production_unit_id' => $delivery->return_production_unit_id, 'saldo_huevos' => $balance,
        ];
    }
}
