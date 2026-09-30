<?php

namespace App\Actions\ManagementPlans;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Exceptions\Lots\LotsConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\ManagementPlans\MedicineStockBalance;
use App\Models\ManagementPlans\MedicineStockMovement;
use App\Models\SuppliersAndCatalogs\Medicine;
use App\Models\User;
use App\Services\Lots\LotsAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AdjustMedicineStockAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /** @param array{quantity_delta: int, reason: string, idempotency_key: string} $attributes */
    public function execute(Medicine $medicine, array $attributes, User $actor, string $source = 'api'): MedicineStockMovement
    {
        if (! Str::isUuid($attributes['idempotency_key']) || $attributes['quantity_delta'] === 0) {
            throw new LotsConflict('El ajuste requiere una clave de idempotencia y una variación entera distinta de cero.');
        }
        $key = Str::lower($attributes['idempotency_key']);
        $reason = trim($attributes['reason']);
        $quantityDelta = $attributes['quantity_delta'];
        $requestHash = hash('sha256', json_encode([
            'medicine' => $medicine->public_id,
            'quantity_delta' => $quantityDelta,
            'reason' => $reason,
        ], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($medicine, $actor, $source, $key, $reason, $quantityDelta, $requestHash): MedicineStockMovement {
                $currentActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
                if ($currentActor === null) {
                    throw new AuthorizationException;
                }
                LotsAuthorization::ensure($currentActor, 'management-plans.stock.manage');

                $existing = MedicineStockMovement::query()
                    ->where('created_by', $currentActor->getKey())
                    ->where('idempotency_key', $key)
                    ->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->request_hash, $requestHash)) {
                        throw new LotsConflict('La clave de idempotencia ya fue usada con otros datos.');
                    }

                    return $existing;
                }

                $lockedMedicine = Medicine::query()->whereKey($medicine->getKey())->lockForUpdate()->firstOrFail();
                $balance = MedicineStockBalance::query()->firstOrCreate(
                    ['medicine_id' => $lockedMedicine->getKey()],
                    ['on_hand_quantity' => 0, 'version' => 1],
                );
                $balance = MedicineStockBalance::query()->whereKey($balance->getKey())->lockForUpdate()->firstOrFail();
                $currentBalance = (int) $balance->on_hand_quantity;
                if (($quantityDelta > 0 && $currentBalance > PHP_INT_MAX - $quantityDelta)
                    || ($quantityDelta < 0 && $currentBalance < PHP_INT_MIN - $quantityDelta)) {
                    throw new LotsConflict('El saldo de medicamentos excede el límite que puede registrar el sistema.');
                }
                $balanceAfter = $currentBalance + $quantityDelta;
                $balance->forceFill([
                    'on_hand_quantity' => $balanceAfter,
                    'version' => $balance->version + 1,
                ])->save();

                $movement = new MedicineStockMovement;
                $movement->forceFill([
                    'public_id' => (string) Str::ulid(),
                    'medicine_id' => $lockedMedicine->getKey(),
                    'medicine_public_id_snapshot' => $lockedMedicine->public_id,
                    'medicine_name_snapshot' => $lockedMedicine->name,
                    'movement_type' => 'adjustment',
                    'quantity_delta' => $quantityDelta,
                    'balance_after' => $balanceAfter,
                    'reason' => $reason,
                    'operation_id' => (string) Str::uuid(),
                    'idempotency_key' => $key,
                    'request_hash' => $requestHash,
                    'created_by' => $currentActor->getKey(),
                ])->save();

                $snapshot = [
                    'id' => $movement->public_id,
                    'medicine' => [
                        'id' => $lockedMedicine->public_id,
                        'name_at_adjustment' => $lockedMedicine->name,
                    ],
                    'movement_type' => $movement->movement_type,
                    'quantity_delta' => $quantityDelta,
                    'balance_after' => $balanceAfter,
                    'reason' => $reason,
                    'operation_id' => $movement->operation_id,
                ];
                $this->auditRecorder->record(AuditEntryData::forSubject(
                    subject: $movement,
                    actor: $currentActor,
                    logName: 'management_plans',
                    event: 'medicine_stock_adjusted',
                    description: 'Saldo interno de medicamento ajustado',
                    operationId: $movement->operation_id,
                    source: $source,
                    properties: ['result' => 'success', 'subject_snapshot' => $snapshot],
                    attributeChanges: ['old' => ['balance' => $currentBalance], 'new' => $snapshot],
                ));

                return $movement->refresh();
            }, 3);
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23505') {
                throw $exception;
            }

            $existingAfterRace = MedicineStockMovement::query()
                ->where('created_by', $actor->getKey())
                ->where('idempotency_key', $key)
                ->first();
            if ($existingAfterRace === null) {
                throw $exception;
            }
            if (! hash_equals($existingAfterRace->request_hash, $requestHash)) {
                throw new LotsConflict('La clave de idempotencia ya fue usada con otros datos.', previous: $exception);
            }

            return $existingAfterRace;
        }
    }
}
