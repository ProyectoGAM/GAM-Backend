<?php

namespace App\Services\Lots;

use App\Models\Lots\EggCollection;
use App\Models\Lots\Flock;
use App\Models\Lots\FlockMovement;
use App\Models\Lots\MortalityRecord;
use App\Models\Lots\Weighing;
use App\Models\ManagementPlans\FlockPlan;
use App\Models\ManagementPlans\ManagementExecutionCorrection;
use App\Models\ManagementPlans\ManualPractice;
use App\Models\ManagementPlans\MedicineApplication;
use App\Models\ManagementPlans\RationChange;
use App\Models\ManagementPlans\VaccinationApplication;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class FlockActivityJournal
{
    public function record(Flock $flock, string $operationId, string $kind, string $command): void
    {
        DB::table('flock_activities')->insert([
            'flock_id' => $flock->id,
            'operation_id' => $operationId,
            'kind' => $kind,
            'command' => $command,
            'created_at' => now(),
        ]);
    }

    /** @param iterable<Flock> $flocks */
    public function recordMany(iterable $flocks, string $operationId, string $kind, string $command): void
    {
        $unique = [];
        foreach ($flocks as $flock) {
            $unique[$flock->id] = $flock;
        }
        foreach ($unique as $flock) {
            $this->record($flock, $operationId, $kind, $command);
        }
    }

    public function isLatestFor(Flock $flock, string $operationId): bool
    {
        $latest = DB::table('flock_activities')
            ->where('flock_id', $flock->id)
            ->orderByDesc('id')
            ->value('operation_id');

        return $latest !== null && hash_equals((string) $latest, $operationId);
    }

    /**
     * Comprueba que después de una terminalidad por mortalidad sólo hubo correcciones neutras sobre el lote agotado.
     */
    public function hasOnlyNeutralMortalityCorrectionsAfter(Flock $flock, string $operationId): bool
    {
        $activity = DB::table('flock_activities')
            ->where('flock_id', $flock->id)
            ->where('operation_id', $operationId)
            ->orderByDesc('id')
            ->first(['id']);
        if ($activity === null) {
            return false;
        }

        $operationIds = DB::table('flock_activities')
            ->where('flock_id', $flock->id)
            ->where('id', '>', $activity->id)
            ->orderBy('id')
            ->pluck('operation_id')
            ->unique()
            ->values();

        foreach ($operationIds as $laterOperationId) {
            $movements = FlockMovement::query()
                ->where('operation_id', $laterOperationId)
                ->where(fn ($query) => $query->where('source_flock_id', $flock->id)->orWhere('destination_flock_id', $flock->id))
                ->get();
            if ($movements->isEmpty()) {
                return false;
            }

            foreach ($movements as $movement) {
                $before = $movement->before[$flock->public_id] ?? null;
                $after = $movement->after[$flock->public_id] ?? null;
                if ($movement->type !== 'mortality_correction'
                    || $movement->quantity !== 0
                    || ! is_array($before)
                    || ! is_array($after)
                    || ($before['status'] ?? null) !== 'finished'
                    || ($after['status'] ?? null) !== 'finished'
                    || (int) ($before['current_quantity'] ?? -1) !== 0
                    || (int) ($after['current_quantity'] ?? -1) !== 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Rechaza actividad histórica posterior sin una marca ordenada; debe ejecutarse con el lote bloqueado.
     */
    public function hasNoUnmarkedActivityAfter(Flock $flock, string $operationId): bool
    {
        $terminalMovement = FlockMovement::query()
            ->where('operation_id', $operationId)
            ->where(fn ($query) => $query->where('source_flock_id', $flock->id)->orWhere('destination_flock_id', $flock->id))
            ->orderBy('id')
            ->first(['id', 'operation_id', 'created_at']);
        $terminalActivityId = DB::table('flock_activities')
            ->where('flock_id', $flock->id)
            ->where('operation_id', $operationId)
            ->orderBy('id')
            ->value('id');
        if ($terminalMovement === null || $terminalActivityId === null) {
            return false;
        }

        $laterMovements = FlockMovement::query()
            ->where('id', '>', $terminalMovement->id)
            ->where(fn ($query) => $query->where('source_flock_id', $flock->id)->orWhere('destination_flock_id', $flock->id))
            ->get(['operation_id']);
        foreach ($laterMovements as $laterMovement) {
            if (! $this->hasMarkFor($flock, (string) $laterMovement->operation_id, (int) $terminalActivityId)) {
                return false;
            }
        }

        $terminalAuditId = DB::table('activity_log')->where('operation_id', $operationId)->max('id');
        $audits = DB::table('activity_log')
            ->whereIn('subject_type', [Flock::class, MortalityRecord::class, EggCollection::class, Weighing::class, FlockPlan::class, VaccinationApplication::class, MedicineApplication::class, RationChange::class, ManualPractice::class, ManagementExecutionCorrection::class])
            ->when(
                $terminalAuditId !== null,
                function ($query) use ($terminalAuditId): void {
                    $query->where('id', '>', $terminalAuditId);
                },
                fn ($query) => $query->where('created_at', '>=', $terminalMovement->created_at),
            )
            ->orderBy('id')
            ->get(['operation_id', 'subject_type', 'subject_id', 'created_at']);
        foreach ($audits as $audit) {
            if (! $this->auditBelongsTo($audit, $flock) || (string) $audit->operation_id === $operationId) {
                continue;
            }
            if ($terminalAuditId === null
                && CarbonImmutable::parse((string) $audit->created_at)->equalTo(CarbonImmutable::parse((string) $terminalMovement->created_at))) {
                return false;
            }
            if ($audit->operation_id === null || ! $this->hasMarkFor($flock, (string) $audit->operation_id, (int) $terminalActivityId)) {
                return false;
            }
        }

        return true;
    }

    private function hasMarkFor(Flock $flock, string $operationId, int $afterActivityId): bool
    {
        return DB::table('flock_activities')
            ->where('flock_id', $flock->id)
            ->where('operation_id', $operationId)
            ->where('id', '>', $afterActivityId)
            ->exists();
    }

    private function auditBelongsTo(object $audit, Flock $flock): bool
    {
        return match ($audit->subject_type) {
            Flock::class => (int) $audit->subject_id === $flock->id,
            MortalityRecord::class => (int) DB::table('mortality_records')->where('id', $audit->subject_id)->value('flock_id') === $flock->id,
            EggCollection::class => (int) DB::table('egg_collections')->where('id', $audit->subject_id)->value('flock_id') === $flock->id,
            Weighing::class => (int) DB::table('weighings')->where('id', $audit->subject_id)->value('flock_id') === $flock->id,
            FlockPlan::class => (int) DB::table('flock_plans')->where('id', $audit->subject_id)->value('flock_id') === $flock->id,
            VaccinationApplication::class => (int) DB::table('vaccination_applications')->where('id', $audit->subject_id)->value('flock_id') === $flock->id,
            MedicineApplication::class => (int) DB::table('medicine_applications')->where('id', $audit->subject_id)->value('flock_id') === $flock->id,
            RationChange::class => (int) DB::table('ration_changes')->where('id', $audit->subject_id)->value('flock_id') === $flock->id,
            ManualPractice::class => (int) DB::table('manual_practices')->where('id', $audit->subject_id)->value('flock_id') === $flock->id,
            ManagementExecutionCorrection::class => (int) DB::table('management_execution_corrections')->where('id', $audit->subject_id)->value('flock_id') === $flock->id,
            default => false,
        };
    }
}
