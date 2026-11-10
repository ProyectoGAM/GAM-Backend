<?php

namespace App\Services\IdentityAndAccess;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** Aplica el límite de UP a lecturas, bindings y entidades relacionadas, también antes de agregar o paginar. */
final class ProductionUnitScopeRegistry
{
    private const DIRECT = [
        'FarmStructure\\ProductionUnit' => 'id', 'FarmStructure\\PoultryHouse' => 'production_unit_id',
        'Lots\\Flock' => 'production_unit_id', 'Lots\\Weighing' => 'production_unit_id',
        'Lots\\EggCollection' => 'production_unit_id', 'Lots\\MortalityRecord' => 'production_unit_id',
        'Inventory\\StockLocation' => 'production_unit_id', 'Inventory\\EggStockAccount' => 'production_unit_id',
        'Inventory\\EggStockTransaction' => 'production_unit_id', 'Inventory\\FeedBalance' => 'production_unit_id',
        'Inventory\\FeedConsumption' => 'production_unit_id', 'Inventory\\FeedStockOperation' => 'production_unit_id',
        'Deliveries\\Delivery' => 'production_unit_id', 'Deliveries\\DeliveryLoad' => 'production_unit_id',
        'ReportingAndAnalytics\\ReportExport' => 'production_unit_id', 'ReportingAndAnalytics\\ReportPreset' => 'production_unit_id',
        'Lots\\FlockOperation' => 'production_unit_id', 'Inventory\\EggStockCommand' => 'production_unit_id',
        'AuditAndTraceability\\AuditEntry' => 'up_id',
        'CustomersAndAccounts\\CustomerOperation' => 'production_unit_id',
    ];

    private const RELATED = [
        'FarmStructure\\Maintenance' => ['poultry_house_id', 'poultry_houses', 'production_unit_id'],
        'Inventory\\StockBalance' => ['stock_location_id', 'stock_locations', 'production_unit_id'],
        'Inventory\\InventoryMovementLine' => ['stock_location_id', 'stock_locations', 'production_unit_id'],
        'Inventory\\EggStockTransactionRevision' => ['egg_stock_transaction_id', 'egg_stock_transactions', 'production_unit_id'],
        'Inventory\\FeedConsumptionCorrection' => ['feed_consumption_id', 'feed_consumptions', 'production_unit_id'],
        'Inventory\\FeedConsumptionMovement' => ['feed_consumption_id', 'feed_consumptions', 'production_unit_id'],
        'Inventory\\FlockFeedingChange' => ['flock_id', 'flocks', 'production_unit_id'],
        'Lots\\DailyWeighing' => ['flock_id', 'flocks', 'production_unit_id'],
        'Lots\\WeighingMeasurement' => ['weighing_id', 'weighings', 'production_unit_id'],
        'ManagementPlans\\FlockPlan' => ['flock_id', 'flocks', 'production_unit_id'],
        'ManagementPlans\\VaccinationApplication' => ['flock_id', 'flocks', 'production_unit_id'],
        'ManagementPlans\\MedicineApplication' => ['flock_id', 'flocks', 'production_unit_id'],
        'ManagementPlans\\ManualPractice' => ['flock_id', 'flocks', 'production_unit_id'],
        'ManagementPlans\\RationChange' => ['flock_id', 'flocks', 'production_unit_id'],
        'ManagementPlans\\ManagementExecutionCorrection' => ['flock_id', 'flocks', 'production_unit_id'],
        'ManagementPlans\\FeedSupply' => ['flock_id', 'flocks', 'production_unit_id'],
        'Deliveries\\DeliveryStop' => ['delivery_id', 'deliveries', 'production_unit_id'],
        'Deliveries\\DeliveryLocationPoint' => ['delivery_id', 'deliveries', 'production_unit_id'],
        'CustomersAndAccounts\\CustomerAccountMovement' => ['delivery_id', 'deliveries', 'production_unit_id'],
    ];

    public function register(): void
    {
        foreach (array_unique([...array_keys(self::DIRECT), ...array_keys(self::RELATED), 'Lots\\DailyWeighingEntry', 'ManagementPlans\\FlockPlanRevision', 'ManagementPlans\\FlockPlanActivity', 'Lots\\FlockMovement', 'Inventory\\InventoryMovement', 'Vehicles\\VehicleFuelEntry']) as $name) {
            $class = 'App\\Models\\'.$name;
            $model = new $class;
            $model->addGlobalScope('production_unit_access', function (Builder $query) use ($name): void {
                $unitId = app(ProductionUnitAccess::class)->unitId();
                if ($unitId !== null) {
                    $this->constrain($query, $name, $unitId);
                }
            });
            $class::saving(function (Model $subject) use ($name): void {
                $unitId = app(ProductionUnitAccess::class)->unitId();
                if ($unitId === null) {
                    return;
                }
                if (isset(self::DIRECT[$name])) {
                    if (! $subject->exists && in_array($name, ['ReportingAndAnalytics\\ReportExport', 'ReportingAndAnalytics\\ReportPreset', 'Lots\\FlockOperation', 'Inventory\\EggStockCommand', 'CustomersAndAccounts\\CustomerOperation'], true)) {
                        $subject->setAttribute('production_unit_id', $unitId);
                    }
                    abort_unless((int) $subject->getAttribute(self::DIRECT[$name]) === $unitId, 404);
                } elseif (isset(self::RELATED[$name])) {
                    [$foreign, $table, $column] = self::RELATED[$name];
                    abort_unless(DB::table($table)->where('id', $subject->getAttribute($foreign))->where($column, $unitId)->exists(), 404);
                }
            });
        }
    }

    private function constrain(Builder $query, string $name, int $unitId): void
    {
        if (isset(self::DIRECT[$name])) {
            $query->where($query->qualifyColumn(self::DIRECT[$name]), $unitId);
            if ($name === 'Deliveries\\Delivery') {
                $query->where(fn ($q) => $q->whereNull('deliveries.return_production_unit_id')->orWhere('deliveries.return_production_unit_id', $unitId))
                    ->whereNotExists(fn (QueryBuilder $loads) => $loads->selectRaw('1')->from('delivery_loads')->whereColumn('delivery_id', 'deliveries.id')->where('production_unit_id', '!=', $unitId));
            }
            if ($name === 'AuditAndTraceability\\AuditEntry') {
                $query->where($query->qualifyColumn('log_name'), '!=', 'customers_and_accounts');
            }
        } elseif (isset(self::RELATED[$name])) {
            [$foreign, $table, $column] = self::RELATED[$name];
            $query->whereIn($query->qualifyColumn($foreign), DB::table($table)->select('id')->where($column, $unitId));
        } else {
            $table = $query->getModel()->getTable();
            $ids = match ($name) {
                'Lots\\DailyWeighingEntry' => DB::table('daily_weighings')->select('id')->whereIn('flock_id', DB::table('flocks')->select('id')->where('production_unit_id', $unitId)),
                'ManagementPlans\\FlockPlanRevision' => $this->planIds($unitId),
                'ManagementPlans\\FlockPlanActivity' => DB::table('flock_plan_revisions')->select('id')->whereIn('flock_plan_id', $this->planIds($unitId)),
                'Vehicles\\VehicleFuelEntry' => DB::table('vehicle_usages')->select('id')->whereIn('delivery_id', DB::table('deliveries')->select('id')->where('production_unit_id', $unitId)),
                default => null,
            };
            $foreign = match ($name) {
                'Lots\\DailyWeighingEntry' => 'daily_weighing_id',
                'ManagementPlans\\FlockPlanRevision' => 'flock_plan_id',
                'ManagementPlans\\FlockPlanActivity' => 'flock_plan_revision_id',
                'Vehicles\\VehicleFuelEntry' => 'usage_id',
                default => null,
            };
            if ($ids !== null) {
                $query->whereIn($table.'.'.$foreign, $ids);
            } elseif ($name === 'Lots\\FlockMovement') {
                foreach (['source_poultry_house_id', 'destination_poultry_house_id'] as $field) {
                    $query->where(fn ($q) => $q->whereNull($table.'.'.$field)->orWhereIn($table.'.'.$field, DB::table('poultry_houses')->select('id')->where('production_unit_id', $unitId)));
                }
                $query->where(fn ($q) => $q->whereNotNull('source_poultry_house_id')->orWhereNotNull('destination_poultry_house_id'));
            } elseif ($name === 'Inventory\\InventoryMovement') {
                $locations = DB::table('stock_locations')->select('id')->where('production_unit_id', $unitId);
                $query->whereHas('lines')->whereNotExists(function (QueryBuilder $lines) use ($table, $locations): void {
                    $lines->selectRaw('1')->from('inventory_movement_lines')->whereColumn('inventory_movement_id', $table.'.id')->whereNotIn('stock_location_id', $locations);
                });
            }
        }
    }

    private function planIds(int $unitId): QueryBuilder
    {
        return DB::table('flock_plans')->select('id')->whereIn('flock_id', DB::table('flocks')->select('id')->where('production_unit_id', $unitId));
    }
}
