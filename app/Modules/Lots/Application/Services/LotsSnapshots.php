<?php

namespace App\Modules\Lots\Application\Services;

use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\MortalityRecord;
use App\Modules\Lots\Domain\ValueObjects\FlockAge;
use App\Shared\Clock;

final readonly class LotsSnapshots
{
    public function __construct(private Clock $clock) {}

    /** @return array<string, mixed> */
    public function flock(Flock $flock): array
    {
        $age = FlockAge::on($flock->entry_date->format('Y-m-d'), $this->clock->now(), config('lots.timezone'));

        return [
            'public_id' => $flock->public_id, 'code' => $flock->code,
            'breed_id' => $flock->breed_id, 'supplier_id' => $flock->supplier_id,
            'origin' => $flock->origin, 'supplier_name' => $flock->supplier_name,
            'poultry_house_id' => $flock->poultry_house_id, 'production_unit_id' => $flock->production_unit_id,
            'initial_quantity' => $flock->initial_quantity, 'current_quantity' => $flock->current_quantity,
            'entry_date' => $flock->entry_date->format('Y-m-d'), 'established_at' => $flock->established_at->toIso8601String(),
            'age_days' => $age->days, 'current_week' => $age->week,
            'status' => $flock->status->value, 'version' => $flock->version, 'notes' => $flock->notes,
            'finalized_at' => $flock->finalized_at?->toIso8601String(), 'finalization_reason' => $flock->finalization_reason,
        ];
    }

    /** @return array<string, mixed> */
    public function movement(FlockMovement $movement): array
    {
        return [
            'public_id' => $movement->public_id, 'operation_id' => $movement->operation_id,
            'type' => $movement->type, 'quantity' => $movement->quantity,
            'source_flock_id' => $movement->sourceFlock?->public_id,
            'destination_flock_id' => $movement->destinationFlock?->public_id,
            'reverses_movement_id' => $movement->reversedMovement?->public_id,
            'source_poultry_house_id' => $movement->source_poultry_house_id,
            'destination_poultry_house_id' => $movement->destination_poultry_house_id,
            'before' => $movement->before, 'after' => $movement->after,
            'occurred_at' => $movement->occurred_at->toIso8601String(),
            'created_at' => $movement->created_at?->toIso8601String(),
            'reason' => $movement->reason, 'created_by' => $movement->created_by,
        ];
    }

    /** @return array<string, mixed> */
    public function mortality(MortalityRecord $record, Flock $flock): array
    {
        return [
            'public_id' => $record->public_id, 'flock_id' => $flock->public_id,
            'poultry_house_id' => $record->poultry_house_id, 'production_unit_id' => $record->production_unit_id,
            'mortality_category_id' => $record->mortality_category_id, 'quantity' => $record->quantity,
            'occurred_at' => $record->occurred_at->toIso8601String(), 'notes' => $record->notes,
            'status' => $record->status, 'version' => $record->version, 'created_by' => $record->created_by,
        ];
    }

    /** @return array<string, mixed> */
    public function collection(EggCollection $record, Flock $flock): array
    {
        return [
            'public_id' => $record->public_id, 'flock_id' => $flock->public_id,
            'poultry_house_id' => $record->poultry_house_id, 'production_unit_id' => $record->production_unit_id,
            'quantity' => $record->quantity,
            'occurred_at' => $record->occurred_at->toIso8601String(), 'notes' => $record->notes,
            'status' => $record->status, 'version' => $record->version, 'created_by' => $record->created_by,
        ];
    }
}
