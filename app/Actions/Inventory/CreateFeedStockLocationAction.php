<?php

namespace App\Actions\Inventory;

use App\DTO\AuditAndTraceability\AuditEntryData;
use App\Enums\FarmStructure\PoultryHouseType;
use App\Enums\Inventory\StockLocationStatus;
use App\Exceptions\Inventory\InventoryConflict;
use App\Interfaces\AuditAndTraceability\AuditRecorder;
use App\Models\FarmStructure\PoultryHouse;
use App\Models\Inventory\StockLocation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateFeedStockLocationAction
{
    public function __construct(private AuditRecorder $auditRecorder) {}

    /**
     * Materializa la ubicación técnica de una planta dentro de la transacción
     * que crea el galpón, para conservar atomicidad con su auditoría.
     */
    public function execute(PoultryHouse $house, User $actor): StockLocation
    {
        return DB::transaction(
            fn (): StockLocation => $this->executeWithinTransaction($house, $actor),
        );
    }

    private function executeWithinTransaction(PoultryHouse $house, User $actor): StockLocation
    {
        if ($house->type !== PoultryHouseType::Feed) {
            throw new InventoryConflict('Sólo los galpones de tipo planta de ración pueden tener una ubicación de alimentación.');
        }

        $existing = StockLocation::query()
            ->where('poultry_house_id', $house->getKey())
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if (! $existing->system_managed
                || $existing->status !== StockLocationStatus::Active
                || (int) $existing->production_unit_id !== (int) $house->production_unit_id
                || (int) $existing->poultry_house_id !== (int) $house->getKey()) {
                throw new InventoryConflict('La ubicación técnica de la planta de ración no es válida.');
            }

            return $existing->load(['productionUnit', 'poultryHouse']);
        }

        $name = 'Planta de ración #'.$house->getKey();

        try {
            $location = new StockLocation;
            $location->forceFill([
                'production_unit_id' => $house->production_unit_id,
                'poultry_house_id' => $house->getKey(),
                'name' => $name,
                'status' => StockLocationStatus::Active,
                'system_managed' => true,
            ])->save();
        } catch (QueryException $exception) {
            if ((string) ($exception->errorInfo[0] ?? $exception->getCode()) !== '23505') {
                throw $exception;
            }

            throw new InventoryConflict('La ubicación técnica de la planta de ración ya existe.', previous: $exception);
        }

        $snapshot = [
            'production_unit_id' => $location->production_unit_id,
            'poultry_house_id' => $location->poultry_house_id,
            'name' => $location->name,
            'status' => $location->status->value,
            'system_managed' => $location->system_managed,
        ];

        $this->auditRecorder->record(AuditEntryData::forSubject(
            subject: $location,
            actor: $actor,
            logName: 'inventory',
            event: 'feed_stock_location_created',
            description: 'Ubicación técnica de planta de ración creada',
            properties: ['subject_snapshot' => $snapshot],
            attributeChanges: ['old' => [], 'new' => $snapshot],
            upId: $location->production_unit_id,
            operationId: Str::uuid()->toString(),
            source: 'application',
        ));

        return $location->load(['productionUnit', 'poultryHouse']);
    }
}
