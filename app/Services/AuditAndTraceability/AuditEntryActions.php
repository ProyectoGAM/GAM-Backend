<?php

namespace App\Services\AuditAndTraceability;

use App\Enums\Lots\FlockStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\Inventory\FeedConsumption;
use App\Models\Inventory\FeedConsumptionCorrection;
use App\Models\Inventory\FeedStockOperation;
use App\Models\Inventory\InventoryMovement;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\MortalityRecord;
use App\Models\User;
use App\Services\Inventory\InventoryReversalAvailability;
use App\Services\Lots\FlockStatusReversal;
use App\Services\Lots\RedistributionReversal;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class AuditEntryActions
{
    public function __construct(
        private InventoryReversalAvailability $inventory,
        private FlockStatusReversal $statusReversal,
        private RedistributionReversal $redistributionReversal,
    ) {}

    /** @return array<string, mixed> */
    public function forEntry(AuditEntry $entry, User $viewer): array
    {
        if ($entry->event === 'inventory_movement_recorded' && $entry->subject_type === (new InventoryMovement)->getMorphClass()) {
            $movement = InventoryMovement::query()->find($entry->subject_id);
            if ($movement === null) {
                return $this->result('unavailable', 'El movimiento original ya no está disponible.');
            }
            $reason = $this->inventory->currentReason($movement, $viewer);

            return $this->result($reason === null ? 'available' : 'unavailable',
                $reason ?? 'Puedes registrar una compensación conservando el movimiento original.',
                [[
                    'kind' => 'reverse_inventory_movement', 'label' => 'Revertir movimiento',
                    'available' => $reason === null, 'reason' => $reason,
                    'target' => ['resource' => 'inventory_movement', 'id' => $movement->getKey()],
                ]]);
        }
        if (in_array($entry->event, ['feed_consumption_recorded', 'feed_consumption_corrected'], true)) {
            $consumption = null;
            if ($entry->subject_type === (new FeedConsumption)->getMorphClass()) {
                $consumption = FeedConsumption::query()->with('latestCorrection')->find($entry->subject_id);
            } elseif ($entry->subject_type === (new FeedConsumptionCorrection)->getMorphClass()) {
                $correction = FeedConsumptionCorrection::query()->find($entry->subject_id);
                $consumption = $correction === null ? null : FeedConsumption::query()->with('latestCorrection')->find($correction->feed_consumption_id);
            }
            $flock = $consumption === null ? null : Flock::query()->find($consumption->flock_id);
            if ($flock === null) {
                return $this->result('unavailable', 'El consumo o su lote ya no está disponible.');
            }
            $superseded = $consumption->latestCorrection !== null && ($entry->event === 'feed_consumption_recorded' || (int) $consumption->latestCorrection->id !== (int) $entry->subject_id);
            $reason = $superseded ? 'Este registro ya fue rectificado. Consulta el consumo vigente para registrar otra rectificación.' : null;
            if ($reason === null && ! $viewer->can('correctConsumption', FeedStockOperation::class)) {
                $reason = 'No tienes permiso para rectificar consumos de alimento.';
            }

            return $this->result($reason === null ? 'available' : 'unavailable',
                $reason ?? 'Puedes rectificar el consumo mediante una compensación. La configuración de alimentación no cambia.',
                [[
                    'kind' => 'correct_feed_consumption', 'label' => 'Rectificar consumo',
                    'available' => $reason === null, 'reason' => $reason,
                    'target' => ['resource' => 'feed_consumption', 'id' => $consumption->id, 'flock_id' => $flock->public_id, 'production_unit_id' => $flock->production_unit_id],
                ]]);
        }
        if (in_array($entry->event, ['mortality_recorded', 'mortality_corrected', 'mortality_cancelled', 'flock_mortality_applied', 'flock_mortality_corrected'], true)) {
            return $this->mortality($entry, $viewer);
        }
        if ($entry->log_name === 'lots' && in_array($entry->event, ['flock_status_changed', 'flock_finalized', 'flock_redistributed'], true)) {
            if ($entry->event === 'flock_finalized') {
                return $this->result('irreversible', 'La finalización explícita del lote no admite reversión.');
            }
            if ($entry->subject_type !== (new Flock)->getMorphClass() || $entry->subject_id === null) {
                return $this->result('unavailable', 'La entrada no conserva una referencia verificable al lote.');
            }

            return $entry->event === 'flock_status_changed'
                ? $this->flockStatus($entry, $viewer)
                : $this->redistribution($entry, $viewer);
        }
        if (in_array($entry->event, ['user_logged_out', 'shared_pin_login', 'shared_device_pairing_code_created', 'report_export_downloaded', 'report_export_expired'], true)) {
            return $this->result('irreversible', 'Este hecho histórico no admite una operación de reversión.');
        }

        return $this->result('unknown', 'La disponibilidad de correcciones para esta actividad debe consultarse en su módulo de origen.');
    }

    /** @return array<string, mixed> */
    private function flockStatus(AuditEntry $entry, User $viewer): array
    {
        $flock = Flock::query()->find($entry->subject_id);
        if ($flock === null || ($entry->up_id !== null && $entry->up_id !== $flock->production_unit_id)) {
            return $this->result('unavailable', 'El lote de este cambio de estado ya no está disponible.');
        }
        if ($flock->status === FlockStatus::Finished) {
            return $this->result('irreversible', 'El lote está finalizado y su estado no se puede revertir desde esta entrada.');
        }

        $target = ['resource' => 'flock', 'id' => $flock->public_id];
        $reason = null;
        if (! $viewer->can('changeStatus', $flock)) {
            $reason = 'No tienes permiso para revertir cambios de estado de lotes.';
        } else {
            try {
                $previous = $this->statusReversal->previousStatus($entry, $flock);
                $target += ['status' => $previous->value, 'version' => $flock->version];
            } catch (LotsConflict $conflict) {
                $reason = $conflict->getMessage();
            }
        }

        return $this->lotAction('reverse_flock_status', 'Revertir cambio de estado', $target, $reason,
            'Puedes restituir el estado anterior del lote conservando el cambio original.');
    }

    /** @return array<string, mixed> */
    private function redistribution(AuditEntry $entry, User $viewer): array
    {
        $unavailable = 'No se encontró una redistribución verificable para esta entrada.';
        if ($entry->operation_id === null || $entry->subject_id === null) {
            return $this->result('unavailable', $unavailable);
        }

        $movements = FlockMovement::query()->where('operation_id', $entry->operation_id)
            ->whereIn('type', RedistributionReversal::ORIGINAL_TYPES)
            ->where(fn ($query) => $query->where('source_flock_id', $entry->subject_id)
                ->orWhere('destination_flock_id', $entry->subject_id))
            ->with(['sourceFlock', 'destinationFlock'])
            ->limit(2)->get();
        $movement = $movements->count() === 1 ? $movements->first() : null;
        if ($movement === null || $movement->sourceFlock === null || $movement->destinationFlock === null
            || ($entry->up_id !== null && ($entry->up_id !== $movement->sourceFlock->production_unit_id
                || $entry->up_id !== $movement->destinationFlock->production_unit_id))) {
            return $this->result('unavailable', $unavailable);
        }

        $target = ['resource' => 'flock_redistribution', 'id' => $movement->public_id];
        $reason = null;
        if (! $viewer->can('redistribute', Flock::class)) {
            $reason = 'No tienes permiso para revertir redistribuciones de lotes.';
        } else {
            try {
                ['source' => $source, 'destination' => $destination] = $this->redistributionReversal->prepare($movement);
                $target['version'] = $source->version;
                if ($source->id !== $destination->id) {
                    $target['destination_version'] = $destination->version;
                }
            } catch (ModelNotFoundException) {
                return $this->result('unavailable', $unavailable);
            } catch (LotsConflict $conflict) {
                $reason = $conflict->getMessage();
            }
        }

        return $this->lotAction('reverse_flock_redistribution', 'Revertir redistribución', $target, $reason,
            'Puedes compensar la redistribución conservando el movimiento original.');
    }

    /** @param array<string, mixed> $target @return array<string, mixed> */
    private function lotAction(string $kind, string $label, array $target, ?string $reason, string $availableReason): array
    {
        return $this->result($reason === null ? 'available' : 'unavailable', $reason ?? $availableReason, [[
            'kind' => $kind, 'label' => $label, 'available' => $reason === null, 'reason' => $reason, 'target' => $target,
        ]]);
    }

    /** @return array<string, mixed> */
    private function mortality(AuditEntry $entry, User $viewer): array
    {
        $recordType = (new MortalityRecord)->getMorphClass();
        $source = $entry;
        if ($entry->subject_type === (new Flock)->getMorphClass()) {
            $source = AuditEntry::query()->where('operation_id', $entry->operation_id)
                ->where('subject_type', $recordType)
                ->whereIn('event', ['mortality_recorded', 'mortality_corrected', 'mortality_cancelled'])
                ->first();
        }
        $record = $source?->subject_type === $recordType
            ? MortalityRecord::query()->with('flock')->find($source->subject_id) : null;
        if ($record === null
            || ($entry->subject_type === (new Flock)->getMorphClass() && $record->flock_id !== (int) $entry->subject_id)) {
            return $this->result('unavailable', 'El registro de mortalidad o su lote ya no está disponible.');
        }
        $snapshot = $source->properties?->get('subject_snapshot') ?? $source->attribute_changes?->get('new') ?? [];
        $version = is_array($snapshot) ? ($snapshot['version'] ?? null) : null;
        $reason = match (true) {
            $record->status !== 'recorded' => 'Este registro de mortalidad ya fue deshecho.',
            ! $viewer->can('update', $record) || ! $viewer->can('view', $record) || ! $viewer->can('view', $record->flock) => 'No tienes permiso para deshacer este registro de mortalidad.',
            ! is_int($version) || $version !== $record->version => 'Este registro fue rectificado o no conserva su versión. Consulta la mortalidad vigente para continuar.',
            default => null,
        };

        return $this->result($reason === null ? 'available' : 'unavailable',
            $reason ?? 'Puedes deshacer este registro desde Mortandad. Se solicitará un motivo y se comprobará el estado actual del lote.',
            [[
                'kind' => 'undo_mortality', 'label' => 'Deshacer', 'available' => $reason === null, 'reason' => $reason,
                'target' => ['resource' => 'mortality_record', 'id' => $record->public_id, 'flock_id' => $record->flock->public_id],
            ]]);
    }

    /** @param list<array<string, mixed>> $actions @return array<string, mixed> */
    private function result(string $state, string $reason, array $actions = []): array
    {
        return ['reversibility' => ['state' => $state, 'reason' => $reason], 'actions' => $actions];
    }
}
