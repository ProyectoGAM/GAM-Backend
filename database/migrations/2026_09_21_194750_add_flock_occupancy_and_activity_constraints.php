<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertExistingDataIsCompatible();

        Schema::create('flock_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('flock_id')->constrained('flocks')->restrictOnDelete();
            $table->uuid('operation_id');
            $table->string('kind', 80);
            $table->string('command', 80);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['flock_id', 'operation_id']);
            $table->index(['flock_id', 'id']);
        });
        DB::statement('ALTER TABLE flock_activities ADD CONSTRAINT flock_activities_operation_fk FOREIGN KEY (operation_id) REFERENCES flock_operations (operation_id) DEFERRABLE INITIALLY DEFERRED');

        Schema::table('flock_movements', function (Blueprint $table): void {
            $table->boolean('reversal_verified')->default(false);
        });

        DB::statement("CREATE UNIQUE INDEX flocks_open_house_unique ON flocks (poultry_house_id) WHERE status IN ('active', 'quarantined')");
        DB::statement('ALTER TABLE flocks ADD CONSTRAINT flocks_status_quantity_check CHECK ((status = \'finished\') = (current_quantity = 0))');
        DB::statement('ALTER TABLE flock_movements DROP CONSTRAINT IF EXISTS flock_movements_type_check');
        DB::statement("ALTER TABLE flock_movements ADD CONSTRAINT flock_movements_type_check CHECK (type IN ('admission', 'partial_new', 'partial_existing', 'total', 'total_existing', 'departure', 'mortality', 'mortality_correction', 'redistribution_reversal'))");

        $this->rebuildLegacyActivityJournal();
    }

    public function down(): void
    {
        $hasTotalExisting = DB::table('flock_movements')->where('type', 'total_existing')->exists();
        DB::statement('ALTER TABLE flock_movements DROP CONSTRAINT IF EXISTS flock_movements_type_check');
        $legacyMovementTypes = "'admission', 'partial_new', 'partial_existing', 'total', 'departure', 'mortality', 'mortality_correction', 'redistribution_reversal'";
        if ($hasTotalExisting) {
            $legacyMovementTypes .= ", 'total_existing'";
        }
        DB::statement("ALTER TABLE flock_movements ADD CONSTRAINT flock_movements_type_check CHECK (type IN ({$legacyMovementTypes}))");
        DB::statement('ALTER TABLE flocks DROP CONSTRAINT IF EXISTS flocks_status_quantity_check');
        DB::statement('DROP INDEX IF EXISTS flocks_open_house_unique');
        Schema::table('flock_movements', function (Blueprint $table): void {
            $table->dropColumn('reversal_verified');
        });
        Schema::dropIfExists('flock_activities');
    }

    private function assertExistingDataIsCompatible(): void
    {
        $zeroOpen = DB::table('flocks')
            ->whereIn('status', ['active', 'quarantined'])
            ->where('current_quantity', 0)
            ->pluck('id')
            ->all();
        $finishedInvalid = DB::table('flocks')
            ->where('status', 'finished')
            ->where(function ($query): void {
                $query->where('current_quantity', '<>', 0)->orWhereNull('finalized_at');
            })
            ->pluck('id')
            ->all();
        $occupiedTwice = DB::table('flocks')
            ->select('poultry_house_id')
            ->whereIn('status', ['active', 'quarantined'])
            ->groupBy('poultry_house_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('poultry_house_id')
            ->all();

        if ($zeroOpen !== [] || $finishedInvalid !== [] || $occupiedTwice !== []) {
            throw new RuntimeException(sprintf(
                'No se pueden imponer las restricciones de Lotes: lotes abiertos sin aves [%s], lotes finalizados incompatibles [%s], galpones con más de un lote abierto [%s]. Resuelve los datos mediante operaciones autorizadas y auditadas antes de reintentar.',
                implode(', ', $zeroOpen),
                implode(', ', $finishedInvalid),
                implode(', ', $occupiedTwice),
            ));
        }
    }

    private function rebuildLegacyActivityJournal(): void
    {
        $verifiedMovementIds = [];
        $redistributionMovements = [];
        $operationRows = DB::table('flock_operations')
            ->orderBy('id')
            ->get(['id', 'operation_id', 'command']);
        $operationIds = array_values(array_map(static fn (mixed $operationId): string => (string) $operationId, $operationRows->pluck('operation_id')->all()));
        $operationEvidence = [];

        foreach ($operationRows as $operation) {
            $operationId = (string) $operation->operation_id;
            $activities = [];
            $movements = DB::table('flock_movements')
                ->where('operation_id', $operationId)
                ->orderBy('id')
                ->get(['id', 'operation_id', 'source_flock_id', 'destination_flock_id', 'type', 'quantity', 'before', 'after', 'created_at']);
            foreach ($movements as $movement) {
                foreach (array_unique(array_filter([$movement->source_flock_id, $movement->destination_flock_id])) as $flockId) {
                    $activities[(int) $flockId] = [
                        'kind' => 'legacy_movement',
                        'command' => 'legacy.'.$movement->type,
                        'created_at' => $movement->created_at,
                    ];
                }
            }

            $subjects = DB::table('activity_log')
                ->where('operation_id', $operationId)
                ->whereIn('subject_type', [
                    'App\\Models\\Lots\\Flock',
                    'App\\Models\\Lots\\MortalityRecord',
                    'App\\Models\\Lots\\EggCollection',
                    'App\\Models\\Lots\\Weighing',
                ])
                ->orderBy('id')
                ->get(['subject_type', 'subject_id', 'event', 'attribute_changes', 'properties', 'created_at']);
            $auditedFlockIds = [];
            $auditComplete = $subjects->isNotEmpty();
            foreach ($subjects as $subject) {
                $subjectFlockIds = $this->flockIdsFromAudit($subject);
                if ($subjectFlockIds === []) {
                    $auditComplete = false;
                }
                foreach ($subjectFlockIds as $flockId) {
                    $auditedFlockIds[] = $flockId;
                    $activities[$flockId] = [
                        'kind' => 'legacy_'.$subject->event,
                        'command' => 'legacy.audit',
                        'created_at' => $subject->created_at,
                    ];
                }
            }

            $movementFlockIds = [];
            foreach ($movements as $movement) {
                foreach (array_unique(array_filter([(int) $movement->source_flock_id, (int) $movement->destination_flock_id])) as $flockId) {
                    $movementFlockIds[] = (int) $flockId;
                }
            }
            $operationFlockIds = array_values(array_unique(array_merge($movementFlockIds, array_map('intval', $auditedFlockIds))));
            $touchesFlocks = $this->legacyCommandTouchesFlocks((string) $operation->command);
            $hasFlockEvidence = $operationFlockIds !== [];
            $operationEvidence[$operationId] = [
                'id' => (int) $operation->id,
                'touches_flocks' => $touchesFlocks,
                'complete' => $this->legacyOperationIsComplete($touchesFlocks, $hasFlockEvidence, $movementFlockIds, $auditedFlockIds, $auditComplete),
                'flock_ids' => $operationFlockIds,
            ];

            foreach ($activities as $flockId => $activity) {
                DB::table('flock_activities')->insertOrIgnore([
                    'flock_id' => $flockId,
                    'operation_id' => $operationId,
                    ...$activity,
                ]);
            }

            foreach ($movements as $movement) {
                if (in_array($movement->type, ['partial_new', 'partial_existing', 'total', 'total_existing'], true)) {
                    $redistributionMovements[] = $movement;
                }
            }
        }

        $orphanFlockIds = $this->legacyOrphanFlockIds($operationIds);
        foreach ($redistributionMovements as $movement) {
            if ($this->canVerifyLegacyRedistribution($movement, $operationEvidence, $orphanFlockIds)) {
                $verifiedMovementIds[] = (int) $movement->id;
            }
        }

        if ($verifiedMovementIds !== []) {
            DB::table('flock_movements')
                ->whereIn('id', $verifiedMovementIds)
                ->whereIn('type', ['partial_new', 'partial_existing', 'total', 'total_existing'])
                ->update(['reversal_verified' => true]);
        }
    }

    private function legacyCommandTouchesFlocks(string $command): bool
    {
        return ! in_array($command, ['breed.save', 'mortality_category.save', 'weighing-settings.save'], true);
    }

    /**
     * Determina si las fuentes históricas permiten afirmar que una operación que podía tocar lotes quedó completamente reconstruida.
     *
     * @param  list<int>  $movementFlockIds
     * @param  list<int>  $auditedFlockIds
     */
    private function legacyOperationIsComplete(bool $touchesFlocks, bool $hasFlockEvidence, array $movementFlockIds, array $auditedFlockIds, bool $auditComplete): bool
    {
        if (! $touchesFlocks) {
            return ! $hasFlockEvidence;
        }
        if (! $auditComplete || ! $hasFlockEvidence) {
            return false;
        }

        return array_diff($movementFlockIds, array_map('intval', $auditedFlockIds)) === [];
    }

    /**
     * @param  array<string, array{id: int, touches_flocks: bool, complete: bool, flock_ids: list<int>}>  $operationEvidence
     * @param  list<int>  $orphanFlockIds
     */
    private function canVerifyLegacyRedistribution(object $movement, array $operationEvidence, array $orphanFlockIds): bool
    {
        if (! in_array($movement->type, ['partial_new', 'partial_existing', 'total', 'total_existing'], true)) {
            return false;
        }
        $flockIds = array_values(array_unique(array_filter([(int) $movement->source_flock_id, (int) $movement->destination_flock_id])));
        if ($flockIds === [] || array_intersect($flockIds, $orphanFlockIds) !== []) {
            return false;
        }
        $evidence = $operationEvidence[(string) $movement->operation_id] ?? null;
        if ($evidence === null || ! $evidence['touches_flocks'] || ! $evidence['complete'] || array_diff($flockIds, $evidence['flock_ids']) !== []) {
            return false;
        }
        $flockRows = DB::table('flocks')->whereIn('id', $flockIds)->get(['id', 'public_id']);
        if ($flockRows->count() !== count($flockIds)) {
            return false;
        }
        $publicIdsByFlockId = [];
        foreach ($flockRows as $flockRow) {
            $publicIdsByFlockId[(int) $flockRow->id] = (string) $flockRow->public_id;
        }
        $expectedPublicIds = array_values($publicIdsByFlockId);
        $before = is_string($movement->before) ? json_decode($movement->before, true) : $movement->before;
        $after = is_string($movement->after) ? json_decode($movement->after, true) : $movement->after;
        if (! is_array($before) || ! is_array($after)) {
            return false;
        }

        $afterKeys = array_map('strval', array_keys($after));
        $beforeKeys = array_map('strval', array_keys($before));
        if (array_diff($expectedPublicIds, $afterKeys) !== []
            || array_diff($afterKeys, $expectedPublicIds) !== []
            || array_diff($beforeKeys, $expectedPublicIds) !== []) {
            return false;
        }

        $sourcePublicId = $publicIdsByFlockId[(int) $movement->source_flock_id] ?? null;
        if ($sourcePublicId === null || ! array_key_exists($sourcePublicId, $before) || ! array_key_exists($sourcePublicId, $after)) {
            return false;
        }
        $requiredBeforeIds = $expectedPublicIds;
        if ($movement->destination_flock_id !== null && $movement->destination_flock_id !== $movement->source_flock_id) {
            $destinationPublicId = $publicIdsByFlockId[(int) $movement->destination_flock_id] ?? null;
            if ($destinationPublicId === null || ! array_key_exists($destinationPublicId, $after)) {
                return false;
            }
            if (in_array($movement->type, ['partial_existing', 'total_existing'], true) && ! array_key_exists($destinationPublicId, $before)) {
                return false;
            }
            if ($movement->type === 'partial_new') {
                $requiredBeforeIds = array_values(array_diff($requiredBeforeIds, [$destinationPublicId]));
            }
        } elseif ($movement->type !== 'total') {
            return false;
        }
        if (array_diff($requiredBeforeIds, $beforeKeys) !== []) {
            return false;
        }

        foreach ($after as $publicId => $snapshot) {
            if (! $this->legacySnapshotIsCoherent($snapshot, (string) $publicId)) {
                return false;
            }
        }
        foreach ($before as $publicId => $snapshot) {
            if (! $this->legacySnapshotIsCoherent($snapshot, (string) $publicId)) {
                return false;
            }
        }

        $snapshotHouseIds = [];
        foreach ([...array_values($before), ...array_values($after)] as $snapshot) {
            $snapshotHouseIds[] = (int) $snapshot['poultry_house_id'];
        }
        $houseProductionUnits = DB::table('poultry_houses')
            ->whereIn('id', array_values(array_unique($snapshotHouseIds)))
            ->pluck('production_unit_id', 'id');
        foreach ([...array_values($before), ...array_values($after)] as $snapshot) {
            $houseId = (int) $snapshot['poultry_house_id'];
            if (! $houseProductionUnits->has($houseId) || (int) $houseProductionUnits->get($houseId) !== (int) $snapshot['production_unit_id']) {
                return false;
            }
        }

        if ($movement->type === 'total_existing') {
            $sourceBefore = $before[$sourcePublicId] ?? null;
            $sourceAfter = $after[$sourcePublicId] ?? null;
            $destinationPublicId = $publicIdsByFlockId[(int) $movement->destination_flock_id] ?? null;
            $destinationBefore = $destinationPublicId === null ? null : ($before[$destinationPublicId] ?? null);
            $destinationAfter = $destinationPublicId === null ? null : ($after[$destinationPublicId] ?? null);
            if (! is_array($sourceBefore)
                || ! is_array($sourceAfter)
                || ! is_array($destinationBefore)
                || ! is_array($destinationAfter)
                || ! in_array($sourceBefore['status'] ?? null, ['active', 'quarantined'], true)
                || ($sourceAfter['status'] ?? null) !== 'finished'
                || (int) ($sourceBefore['current_quantity'] ?? -1) !== (int) $movement->quantity
                || (int) ($sourceAfter['current_quantity'] ?? -1) !== 0
                || ! in_array($destinationBefore['status'] ?? null, ['active', 'quarantined'], true)
                || ! in_array($destinationAfter['status'] ?? null, ['active', 'quarantined'], true)
                || (int) ($destinationAfter['current_quantity'] ?? -1) !== (int) ($destinationBefore['current_quantity'] ?? -1) + (int) $movement->quantity) {
                return false;
            }
        }

        foreach ($operationEvidence as $candidate) {
            if ($candidate['id'] <= $evidence['id'] || ! $candidate['touches_flocks']) {
                continue;
            }
            if (! $candidate['complete']) {
                return false;
            }
        }

        return true;
    }

    /** Comprueba los campos que ReverseRedistributionAction lee directamente de una fotografía histórica. */
    private function legacySnapshotIsCoherent(mixed $snapshot, string $publicId): bool
    {
        if (! is_array($snapshot)) {
            return false;
        }
        foreach (['public_id', 'poultry_house_id', 'production_unit_id', 'current_quantity', 'status', 'version', 'finalized_at', 'finalization_reason'] as $field) {
            if (! array_key_exists($field, $snapshot)) {
                return false;
            }
        }
        if ($snapshot['public_id'] !== $publicId
            || ! is_int($snapshot['poultry_house_id'])
            || $snapshot['poultry_house_id'] <= 0
            || ! is_int($snapshot['production_unit_id'])
            || $snapshot['production_unit_id'] <= 0
            || ! is_int($snapshot['current_quantity'])
            || $snapshot['current_quantity'] < 0
            || ! is_int($snapshot['version'])
            || $snapshot['version'] <= 0
            || ! is_string($snapshot['public_id'])
            || ! in_array($snapshot['status'], ['active', 'quarantined', 'finished'], true)
            || ! (is_string($snapshot['finalized_at']) || $snapshot['finalized_at'] === null)
            || ! (is_string($snapshot['finalization_reason']) || $snapshot['finalization_reason'] === null)) {
            return false;
        }
        if (($snapshot['status'] === 'finished') !== ($snapshot['current_quantity'] === 0)) {
            return false;
        }
        if ($snapshot['status'] === 'finished' && $snapshot['finalized_at'] === null) {
            return false;
        }
        if ($snapshot['status'] !== 'finished' && $snapshot['finalized_at'] !== null) {
            return false;
        }

        return true;
    }

    /**
     * Los registros sin operación no permiten ordenar con seguridad la actividad histórica y vuelven conservadora toda reversión del lote afectado.
     *
     * @param  list<string>  $operationIds
     * @return list<int>
     */
    private function legacyOrphanFlockIds(array $operationIds): array
    {
        $flockIds = [];
        $orphanMovements = DB::table('flock_movements')
            ->when($operationIds !== [], fn ($query) => $query->whereNotIn('operation_id', $operationIds))
            ->get(['source_flock_id', 'destination_flock_id']);
        foreach ($orphanMovements as $movement) {
            $flockIds = [...$flockIds, ...array_filter([(int) $movement->source_flock_id, (int) $movement->destination_flock_id])];
        }

        $orphanSubjects = DB::table('activity_log')
            ->when($operationIds !== [], fn ($query) => $query->whereNotIn('operation_id', $operationIds))
            ->whereIn('subject_type', [
                'App\\Models\\Lots\\Flock',
                'App\\Models\\Lots\\MortalityRecord',
                'App\\Models\\Lots\\EggCollection',
                'App\\Models\\Lots\\Weighing',
            ])
            ->get(['subject_type', 'subject_id', 'attribute_changes', 'properties']);
        foreach ($orphanSubjects as $subject) {
            $flockIds = [...$flockIds, ...$this->flockIdsFromAudit($subject)];
        }

        return array_values(array_unique(array_map('intval', array_filter($flockIds))));
    }

    /** @return list<int> */
    private function flockIdsFromAudit(object $subject): array
    {
        $flockIds = [];
        if ($subject->subject_type === 'App\\Models\\Lots\\Flock') {
            $flockIds[] = (int) $subject->subject_id;
        } elseif ($subject->subject_type === 'App\\Models\\Lots\\MortalityRecord') {
            $flockId = DB::table('mortality_records')->where('id', $subject->subject_id)->value('flock_id');
            if ($flockId !== null) {
                $flockIds[] = (int) $flockId;
            }
        } elseif ($subject->subject_type === 'App\\Models\\Lots\\EggCollection') {
            $flockId = DB::table('egg_collections')->where('id', $subject->subject_id)->value('flock_id');
            if ($flockId !== null) {
                $flockIds[] = (int) $flockId;
            }
        } elseif ($subject->subject_type === 'App\\Models\\Lots\\Weighing') {
            $flockId = DB::table('weighings')->where('id', $subject->subject_id)->value('flock_id');
            if ($flockId !== null) {
                $flockIds[] = (int) $flockId;
            }
        }

        foreach (['attribute_changes', 'properties'] as $column) {
            $value = $subject->{$column};
            if (is_string($value)) {
                $value = json_decode($value, true);
            }
            foreach ($this->flockIdsFromSnapshot($value) as $flockId) {
                $flockIds[] = $flockId;
            }
        }

        return array_values(array_unique($flockIds));
    }

    /** @return list<int> */
    private function flockIdsFromSnapshot(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $flockIds = [];
        foreach ($value as $key => $item) {
            if ($key === 'flock_id') {
                $flockId = $this->resolveFlockId($item);
                if ($flockId !== null) {
                    $flockIds[] = $flockId;
                }
            }
            foreach ($this->flockIdsFromSnapshot($item) as $nestedFlockId) {
                $flockIds[] = $nestedFlockId;
            }
        }

        return array_values(array_unique($flockIds));
    }

    private function resolveFlockId(mixed $value): ?int
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $flockId = DB::table('flocks')->where('id', (int) $value)->value('id');

            return $flockId === null ? null : (int) $flockId;
        }
        if (is_string($value) && $value !== '') {
            $flockId = DB::table('flocks')->where('public_id', $value)->value('id');

            return $flockId === null ? null : (int) $flockId;
        }

        return null;
    }
};
