<?php

namespace App\Services\Lots;

use App\Enums\Lots\FlockStatus;
use App\Exceptions\Lots\LotsConflict;
use App\Models\AuditAndTraceability\AuditEntry;
use App\Models\Lots\Flock;

final class FlockStatusReversal
{
    public function previousStatus(?AuditEntry $entry, Flock $flock): FlockStatus
    {
        if ($entry === null || $entry->log_name !== 'lots' || $entry->event !== 'flock_status_changed'
            || $entry->subject_type !== $flock->getMorphClass() || (int) $entry->subject_id !== $flock->id
            || ($entry->up_id !== null && $entry->up_id !== $flock->production_unit_id)) {
            throw new LotsConflict('No se encontró un cambio de estado verificable para este lote.');
        }
        if ($flock->status === FlockStatus::Finished) {
            throw new LotsConflict('El lote está finalizado y su estado no se puede revertir desde esta entrada.');
        }

        $before = $this->before($entry);
        $after = $this->after($entry);
        if ($before === null || $after === null || $before === $after
            || $before === FlockStatus::Finished || $after === FlockStatus::Finished) {
            throw new LotsConflict('La entrada no conserva una transición verificable entre Activo y En cuarentena.');
        }
        if ($flock->status !== $after) {
            throw new LotsConflict('Este cambio de estado ya no está vigente.');
        }

        $laterEntries = AuditEntry::query()
            ->where('log_name', 'lots')
            ->where('subject_type', $flock->getMorphClass())
            ->where('subject_id', $flock->id)
            ->where('id', '>', $entry->getKey())
            ->whereIn('event', [
                'flock_status_changed', 'flock_finalized', 'flock_redistributed',
                'flock_redistribution_reversed', 'flock_mortality_applied', 'flock_mortality_corrected',
            ])
            ->lazyById(100);

        foreach ($laterEntries as $later) {
            $previous = $this->before($later);
            $next = $this->after($later);
            if (in_array($later->event, ['flock_status_changed', 'flock_finalized'], true) || $previous !== $next) {
                throw new LotsConflict('Este cambio de estado ya no está vigente.');
            }
            if ($previous === null) {
                throw new LotsConflict('No se pudo comprobar que este sea el último cambio de estado del lote.');
            }
        }

        return $before;
    }

    private function before(AuditEntry $entry): ?FlockStatus
    {
        return $this->status(data_get($entry->attribute_changes?->get('old'), 'status'));
    }

    private function after(AuditEntry $entry): ?FlockStatus
    {
        return $this->status(data_get($entry->attribute_changes?->get('new'), 'status')
            ?? data_get($entry->properties?->get('subject_snapshot'), 'status'));
    }

    private function status(mixed $value): ?FlockStatus
    {
        return is_string($value) ? FlockStatus::tryFrom($value) : null;
    }
}
