<?php

namespace App\Queries\ManagementPlans;

use App\Models\Lots\Flock;
use App\Models\ManagementPlans\FlockPlan;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class ListFlockManagementHistoryQuery
{
    /** @param array<string, mixed> $filters */
    public function execute(Flock $flock, array $filters): LengthAwarePaginator
    {
        $scopes = $this->scopes($flock);
        $streams = [
            $this->movementStream($flock, $scopes),
            $this->weighingStream($flock, $scopes),
            $this->eggCollectionStream($flock, $scopes),
            $this->mortalityStream($flock, $scopes),
            $this->vaccinationStream($flock, $scopes),
            $this->medicineStream($flock, $scopes),
            $this->rationStream($flock, $scopes),
            $this->manualPracticeStream($flock, $scopes),
            $this->correctionStream($flock, $scopes),
        ];

        $history = array_shift($streams);
        foreach ($streams as $stream) {
            $history->unionAll($stream);
        }

        $query = DB::query()->fromSub($history, 'management_history');
        if (isset($filters['type'])) {
            if ($filters['type'] === 'flock_movement') {
                $query->where('entity', 'flock_movement');
            } else {
                $query->where('type', $filters['type']);
            }
        }
        if (isset($filters['date_from'])) {
            $from = CarbonImmutable::parse($filters['date_from'], config('lots.timezone'))->startOfDay();
            $query->where('occurred_at', '>=', $from->toIso8601String());
        }
        if (isset($filters['date_to'])) {
            $to = CarbonImmutable::parse($filters['date_to'], config('lots.timezone'))->addDay()->startOfDay();
            $query->where('occurred_at', '<', $to->toIso8601String());
        }

        return $query
            ->orderByDesc('occurred_at')
            ->orderBy('entity')
            ->orderByDesc('source_id')
            ->paginate((int) ($filters['per_page'] ?? 50), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->withQueryString();
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes */
    private function movementStream(Flock $flock, array $scopes): Builder
    {
        $query = DB::table('flock_movements as records')
            ->leftJoin('flocks as source_flock', 'source_flock.id', '=', 'records.source_flock_id')
            ->leftJoin('flocks as destination_flock', 'destination_flock.id', '=', 'records.destination_flock_id')
            ->leftJoin('flock_plan_activities as plan_activity', 'plan_activity.id', '=', 'records.flock_plan_activity_id')
            ->leftJoin('users as responsible_user', 'responsible_user.id', '=', 'records.created_by')
            ->leftJoin('flock_movements as reversed', 'reversed.id', '=', 'records.reverses_movement_id')
            ->selectRaw('records.id as source_id')
            ->selectRaw('records.public_id as id')
            ->selectRaw('records.operation_id as operation_id')
            ->selectRaw('records.occurred_at as occurred_at')
            ->selectRaw('records.type as type')
            ->selectRaw("'lots' as module, 'flock_movement' as entity")
            ->selectRaw('? as flock_id', [$flock->public_id])
            ->selectRaw('plan_activity.public_id as plan_activity_id')
            ->selectRaw('plan_activity.title as plan_activity_title')
            ->selectRaw('records.created_by as responsible_user_id')
            ->selectRaw('responsible_user.name as responsible_name')
            ->selectRaw("jsonb_build_object('quantity', records.quantity, 'source_flock_id', source_flock.public_id, 'destination_flock_id', destination_flock.public_id, 'source_poultry_house_id', records.source_poultry_house_id, 'destination_poultry_house_id', records.destination_poultry_house_id, 'reason', records.reason, 'reverses_movement_id', reversed.public_id, 'before', records.before, 'after', records.after) as module_data");

        return $this->applyScopes($query, $scopes, movement: true)
            ->where('records.type', '<>', 'mortality')
            ->selectRaw($this->scopeCaseSql($scopes, true, 'provenance'), $this->scopeCaseBindings($scopes, true, includeProvenance: true))
            ->selectRaw($this->scopeCaseSql($scopes, true, 'origin_flock_id'), $this->scopeCaseBindings($scopes, true));
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes */
    private function weighingStream(Flock $flock, array $scopes): Builder
    {
        $query = DB::table('weighings as records')
            ->leftJoin('flock_plan_activities as plan_activity', 'plan_activity.id', '=', 'records.flock_plan_activity_id')
            ->leftJoin('users as responsible_user', 'responsible_user.id', '=', 'records.created_by')
            ->selectRaw('records.id as source_id')
            ->selectRaw('records.public_id as id')
            ->selectRaw("COALESCE(records.operation_id, (SELECT source_operation.operation_id FROM flock_operations AS source_operation WHERE source_operation.result->'weighing'->>'id' = records.public_id ORDER BY source_operation.created_at DESC, source_operation.id DESC LIMIT 1)) as operation_id")
            ->selectRaw('records.occurred_at as occurred_at')
            ->selectRaw("'weighing' as type, 'lots' as module, 'weighing' as entity")
            ->selectRaw('? as flock_id', [$flock->public_id])
            ->selectRaw('plan_activity.public_id as plan_activity_id')
            ->selectRaw('plan_activity.title as plan_activity_title')
            ->selectRaw('records.created_by as responsible_user_id')
            ->selectRaw('responsible_user.name as responsible_name')
            ->selectRaw("jsonb_build_object('mode', records.mode, 'poultry_house_id', records.poultry_house_id, 'production_unit_id', records.production_unit_id, 'captured_unit', records.captured_unit, 'notes', records.notes, 'stage', records.stage, 'expected_range', CASE WHEN records.stage IS NULL THEN NULL ELSE jsonb_build_object('min_weight_g', records.min_weight_g, 'max_weight_g', records.max_weight_g, 'unit', 'g') END, 'reference', CASE WHEN records.reference_version IS NULL THEN NULL ELSE jsonb_build_object('version', records.reference_version, 'unit', records.reference_unit, 'adult_from_week', records.reference_adult_from_week, 'chick_min_weight_g', records.reference_chick_min_weight_g, 'chick_max_weight_g', records.reference_chick_max_weight_g, 'adult_min_weight_g', records.reference_adult_min_weight_g, 'adult_max_weight_g', records.reference_adult_max_weight_g) END, 'represented_bird_count', records.represented_bird_count, 'total_weight_g', records.total_weight_g, 'average_weight_g', records.average_weight_g, 'outside_expected_range', records.outside_expected_range, 'version', records.version, 'measurements', COALESCE((SELECT jsonb_agg(jsonb_build_object('position', measurement.position, 'weight_g', measurement.weight_g, 'total_weight_g', measurement.total_weight_g, 'bird_count', measurement.bird_count, 'average_weight_g', measurement.average_weight_g, 'outside_expected_range', measurement.outside_expected_range) ORDER BY measurement.position) FROM weighing_measurements AS measurement WHERE measurement.weighing_id = records.id), '[]'::jsonb)) as module_data");

        return $this->applyScopes($query, $scopes)
            ->selectRaw($this->scopeCaseSql($scopes, false, 'provenance'), $this->scopeCaseBindings($scopes, false, includeProvenance: true))
            ->selectRaw($this->scopeCaseSql($scopes, false, 'origin_flock_id'), $this->scopeCaseBindings($scopes, false));
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes */
    private function eggCollectionStream(Flock $flock, array $scopes): Builder
    {
        $query = DB::table('egg_collections as records')
            ->leftJoin('flock_plan_activities as plan_activity', 'plan_activity.id', '=', 'records.flock_plan_activity_id')
            ->leftJoin('users as responsible_user', 'responsible_user.id', '=', 'records.created_by')
            ->selectRaw('records.id as source_id')
            ->selectRaw('records.public_id as id')
            ->selectRaw("COALESCE(records.operation_id, (SELECT source_operation.operation_id FROM flock_operations AS source_operation WHERE source_operation.result->'collection'->>'public_id' = records.public_id ORDER BY source_operation.created_at DESC, source_operation.id DESC LIMIT 1)) as operation_id")
            ->selectRaw('records.occurred_at as occurred_at')
            ->selectRaw("'egg_collection' as type, 'lots' as module, 'egg_collection' as entity")
            ->selectRaw('? as flock_id', [$flock->public_id])
            ->selectRaw('plan_activity.public_id as plan_activity_id')
            ->selectRaw('plan_activity.title as plan_activity_title')
            ->selectRaw('records.created_by as responsible_user_id')
            ->selectRaw('responsible_user.name as responsible_name')
            ->selectRaw("jsonb_build_object('quantity', records.quantity, 'poultry_house_id', records.poultry_house_id, 'production_unit_id', records.production_unit_id, 'notes', records.notes, 'status', records.status, 'version', records.version) as module_data");

        return $this->applyScopes($query, $scopes)
            ->selectRaw($this->scopeCaseSql($scopes, false, 'provenance'), $this->scopeCaseBindings($scopes, false, includeProvenance: true))
            ->selectRaw($this->scopeCaseSql($scopes, false, 'origin_flock_id'), $this->scopeCaseBindings($scopes, false));
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes */
    private function mortalityStream(Flock $flock, array $scopes): Builder
    {
        $query = DB::table('mortality_records as records')
            ->leftJoin('mortality_categories as category', 'category.id', '=', 'records.mortality_category_id')
            ->leftJoin('flock_plan_activities as plan_activity', 'plan_activity.id', '=', 'records.flock_plan_activity_id')
            ->leftJoin('users as responsible_user', 'responsible_user.id', '=', 'records.created_by')
            ->selectRaw('records.id as source_id')
            ->selectRaw('records.public_id as id')
            ->selectRaw("COALESCE(records.operation_id, (SELECT source_operation.operation_id FROM flock_operations AS source_operation WHERE source_operation.result->'mortality'->>'public_id' = records.public_id ORDER BY source_operation.created_at DESC, source_operation.id DESC LIMIT 1)) as operation_id")
            ->selectRaw('records.occurred_at as occurred_at')
            ->selectRaw("'mortality' as type, 'lots' as module, 'mortality_record' as entity")
            ->selectRaw('? as flock_id', [$flock->public_id])
            ->selectRaw('plan_activity.public_id as plan_activity_id')
            ->selectRaw('plan_activity.title as plan_activity_title')
            ->selectRaw('records.created_by as responsible_user_id')
            ->selectRaw('responsible_user.name as responsible_name')
            ->selectRaw("jsonb_build_object('quantity', records.quantity, 'mortality_category_id', records.mortality_category_id, 'mortality_category_name', category.name, 'poultry_house_id', records.poultry_house_id, 'production_unit_id', records.production_unit_id, 'notes', records.notes, 'status', records.status, 'version', records.version) as module_data");

        return $this->applyScopes($query, $scopes)
            ->selectRaw($this->scopeCaseSql($scopes, false, 'provenance'), $this->scopeCaseBindings($scopes, false, includeProvenance: true))
            ->selectRaw($this->scopeCaseSql($scopes, false, 'origin_flock_id'), $this->scopeCaseBindings($scopes, false));
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes */
    private function vaccinationStream(Flock $flock, array $scopes): Builder
    {
        $query = DB::table('vaccination_applications as records')
            ->selectRaw('records.id as source_id')
            ->selectRaw('records.public_id as id')
            ->selectRaw('records.operation_id as operation_id')
            ->selectRaw('records.occurred_at as occurred_at')
            ->selectRaw("'vaccination' as type, 'management_plans' as module, 'vaccination_application' as entity")
            ->selectRaw('? as flock_id', [$flock->public_id])
            ->selectRaw('records.plan_activity_id as plan_activity_id')
            ->selectRaw('records.plan_activity_title_snapshot as plan_activity_title')
            ->selectRaw('records.responsible_user_id as responsible_user_id')
            ->selectRaw('records.responsible_name_snapshot as responsible_name')
            ->selectRaw("jsonb_build_object('vaccine', records.vaccine_snapshot, 'product', records.product_snapshot, 'notes', records.notes, 'inventory_quantity', records.inventory_quantity, 'stock_location_name', records.stock_location_name_snapshot) as module_data");

        return $this->applyScopes($query, $scopes)
            ->selectRaw($this->scopeCaseSql($scopes, false, 'provenance'), $this->scopeCaseBindings($scopes, false, includeProvenance: true))
            ->selectRaw($this->scopeCaseSql($scopes, false, 'origin_flock_id'), $this->scopeCaseBindings($scopes, false));
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes */
    private function medicineStream(Flock $flock, array $scopes): Builder
    {
        $query = DB::table('medicine_applications as records')
            ->leftJoin('medicine_stock_movements as stock_movement', 'stock_movement.id', '=', 'records.stock_movement_id')
            ->selectRaw('records.id as source_id')
            ->selectRaw('records.public_id as id')
            ->selectRaw('records.operation_id as operation_id')
            ->selectRaw('records.occurred_at as occurred_at')
            ->selectRaw("'medication' as type, 'management_plans' as module, 'medicine_application' as entity")
            ->selectRaw('? as flock_id', [$flock->public_id])
            ->selectRaw('records.plan_activity_id as plan_activity_id')
            ->selectRaw('records.plan_activity_title_snapshot as plan_activity_title')
            ->selectRaw('records.responsible_user_id as responsible_user_id')
            ->selectRaw('records.responsible_name_snapshot as responsible_name')
            ->selectRaw("jsonb_build_object('medicine', jsonb_build_object('id', records.medicine_public_id_snapshot, 'name', records.medicine_name_snapshot, 'description', records.medicine_description_snapshot, 'supplier_name', records.supplier_name_snapshot), 'quantity', records.quantity, 'reason', records.reason, 'notes', records.notes, 'stock_movement', CASE WHEN stock_movement.id IS NULL THEN NULL ELSE jsonb_build_object('id', stock_movement.public_id, 'quantity_delta', stock_movement.quantity_delta, 'balance_after', stock_movement.balance_after) END) as module_data");

        return $this->applyScopes($query, $scopes)
            ->selectRaw($this->scopeCaseSql($scopes, false, 'provenance'), $this->scopeCaseBindings($scopes, false, includeProvenance: true))
            ->selectRaw($this->scopeCaseSql($scopes, false, 'origin_flock_id'), $this->scopeCaseBindings($scopes, false));
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes */
    private function rationStream(Flock $flock, array $scopes): Builder
    {
        $query = DB::table('ration_changes as records')
            ->selectRaw('records.id as source_id')
            ->selectRaw('records.public_id as id')
            ->selectRaw('records.operation_id as operation_id')
            ->selectRaw('records.occurred_at as occurred_at')
            ->selectRaw("'ration_change' as type, 'management_plans' as module, 'ration_change' as entity")
            ->selectRaw('? as flock_id', [$flock->public_id])
            ->selectRaw('records.plan_activity_id as plan_activity_id')
            ->selectRaw('records.plan_activity_title_snapshot as plan_activity_title')
            ->selectRaw('records.responsible_user_id as responsible_user_id')
            ->selectRaw('records.responsible_name_snapshot as responsible_name')
            ->selectRaw("jsonb_build_object('ration_description', records.ration_description, 'finished_feed_product', records.finished_feed_product_snapshot, 'notes', records.notes) as module_data");

        return $this->applyScopes($query, $scopes)
            ->selectRaw($this->scopeCaseSql($scopes, false, 'provenance'), $this->scopeCaseBindings($scopes, false, includeProvenance: true))
            ->selectRaw($this->scopeCaseSql($scopes, false, 'origin_flock_id'), $this->scopeCaseBindings($scopes, false));
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes */
    private function manualPracticeStream(Flock $flock, array $scopes): Builder
    {
        $query = DB::table('manual_practices as records')
            ->selectRaw('records.id as source_id')
            ->selectRaw('records.public_id as id')
            ->selectRaw('records.operation_id as operation_id')
            ->selectRaw('records.occurred_at as occurred_at')
            ->selectRaw("'manual_practice' as type, 'management_plans' as module, 'manual_practice' as entity")
            ->selectRaw('? as flock_id', [$flock->public_id])
            ->selectRaw('records.plan_activity_id as plan_activity_id')
            ->selectRaw('records.plan_activity_title_snapshot as plan_activity_title')
            ->selectRaw('records.responsible_user_id as responsible_user_id')
            ->selectRaw('records.responsible_name_snapshot as responsible_name')
            ->selectRaw("jsonb_build_object('practice_type', records.practice_type, 'title', records.practice_title_snapshot, 'notes', records.notes) as module_data");

        return $this->applyScopes($query, $scopes)
            ->selectRaw($this->scopeCaseSql($scopes, false, 'provenance'), $this->scopeCaseBindings($scopes, false, includeProvenance: true))
            ->selectRaw($this->scopeCaseSql($scopes, false, 'origin_flock_id'), $this->scopeCaseBindings($scopes, false));
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes */
    private function correctionStream(Flock $flock, array $scopes): Builder
    {
        $query = DB::table('management_execution_corrections as records')
            ->leftJoin('users as responsible_user', 'responsible_user.id', '=', 'records.created_by')
            ->selectRaw('records.id as source_id')
            ->selectRaw('records.public_id as id')
            ->selectRaw('records.operation_id as operation_id')
            ->selectRaw('records.occurred_at as occurred_at')
            ->selectRaw("'management_correction' as type, 'management_plans' as module, 'management_execution_correction' as entity")
            ->selectRaw('? as flock_id', [$flock->public_id])
            ->selectRaw('NULL as plan_activity_id, NULL as plan_activity_title')
            ->selectRaw('records.created_by as responsible_user_id')
            ->selectRaw('responsible_user.name as responsible_name')
            ->selectRaw("jsonb_build_object('original_type', records.original_type, 'original_id', records.original_public_id, 'original_operation_id', records.original_operation_id, 'correction_reason', records.correction_reason, 'original_snapshot', records.original_snapshot, 'compensation_snapshot', records.compensation_snapshot) as module_data");

        return $this->applyScopes($query, $scopes)
            ->selectRaw($this->scopeCaseSql($scopes, false, 'provenance'), $this->scopeCaseBindings($scopes, false, includeProvenance: true))
            ->selectRaw($this->scopeCaseSql($scopes, false, 'origin_flock_id'), $this->scopeCaseBindings($scopes, false));
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes */
    private function applyScopes(Builder $query, array $scopes, bool $movement = false): Builder
    {
        return $query->where(function (Builder $query) use ($scopes, $movement): void {
            foreach ($scopes as $scope) {
                $query->orWhere(function (Builder $scopeQuery) use ($scope, $movement): void {
                    $scopeQuery->where(function (Builder $flockQuery) use ($scope, $movement): void {
                        if ($movement) {
                            $flockQuery->where('records.source_flock_id', $scope['flock_id'])
                                ->orWhere('records.destination_flock_id', $scope['flock_id']);
                        } else {
                            $flockQuery->where('records.flock_id', $scope['flock_id']);
                        }
                    });
                    if ($scope['cutoff'] !== null) {
                        $scopeQuery->where('records.occurred_at', '<', $scope['cutoff']);
                    }
                });
            }
        });
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes */
    private function scopeCaseSql(array $scopes, bool $movement, string $column): string
    {
        $sql = 'CASE';
        foreach ($scopes as $scope) {
            $sql .= ' WHEN '.$this->scopeMatchSql($scope, $movement).' THEN ?';
        }
        $sql .= " ELSE '' END as {$column}";

        return $sql;
    }

    /** @param list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> $scopes
     * @return list<int|string>
     */
    private function scopeCaseBindings(array $scopes, bool $movement, bool $includeProvenance = false): array
    {
        $bindings = [];
        foreach ($scopes as $scope) {
            $bindings[] = $scope['flock_id'];
            if ($movement) {
                $bindings[] = $scope['flock_id'];
            }
            if ($scope['cutoff'] !== null) {
                $bindings[] = $scope['cutoff'];
            }
            $bindings[] = $includeProvenance ? $scope['provenance'] : $scope['public_id'];
        }

        return $bindings;
    }

    /** @param array{flock_id: int, public_id: string, provenance: string, cutoff: string|null} $scope */
    private function scopeMatchSql(array $scope, bool $movement): string
    {
        $sql = $movement
            ? '(records.source_flock_id = ? OR records.destination_flock_id = ?)'
            : 'records.flock_id = ?';
        if ($scope['cutoff'] !== null) {
            $sql .= ' AND records.occurred_at < ?';
        }

        return $sql;
    }

    /** @return list<array{flock_id: int, public_id: string, provenance: string, cutoff: string|null}> */
    private function scopes(Flock $flock): array
    {
        $scopes = [[
            'flock_id' => $flock->id,
            'public_id' => $flock->public_id,
            'provenance' => 'direct',
            'cutoff' => null,
        ]];
        $child = $flock;
        $visited = [$flock->id => true];

        for ($depth = 0; $depth < 32; $depth++) {
            $sourceFlockId = FlockPlan::query()->where('flock_id', $child->id)->value('source_flock_id');
            if ($sourceFlockId === null || isset($visited[(int) $sourceFlockId])) {
                break;
            }
            $sourceFlock = Flock::query()->whereKey($sourceFlockId)->first(['id', 'public_id', 'established_at']);
            if ($sourceFlock === null) {
                break;
            }
            $visited[$sourceFlock->id] = true;
            $scopes[] = [
                'flock_id' => $sourceFlock->id,
                'public_id' => $sourceFlock->public_id,
                'provenance' => 'antecedent',
                'cutoff' => $child->established_at->toIso8601String(),
            ];
            $child = $sourceFlock;
        }

        return $scopes;
    }
}
