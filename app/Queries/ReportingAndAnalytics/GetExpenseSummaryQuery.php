<?php

namespace App\Queries\ReportingAndAnalytics;

use App\Enums\FarmStructure\MaintenanceStatus;
use App\Models\Deliveries\Delivery;
use App\Models\User;
use App\Services\IdentityAndAccess\ProductionUnitAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final readonly class GetExpenseSummaryQuery
{
    public function __construct(private ProductionUnitAccess $access) {}

    /** @return array<string, mixed> */
    public function execute(User $actor, string $from, string $to, ?int $unitId): array
    {
        $timezone = config('lots.timezone');
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay()->toIso8601String();
        $end = CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay()->toIso8601String();
        $sources = [
            'mantenimiento_galpones' => ['can_view_poultry_houses', fn (): Builder => $this->farmMaintenance($from, $to, $unitId)],
            'combustible' => ['can_view_vehicle_fuel', fn (): Builder => $this->fuel($start, $end, $unitId)],
        ];
        if ($unitId === null) {
            $sources['mantenimiento_vehiculos'] = ['can_view_vehicle_maintenance', fn (): Builder => $this->vehicleMaintenance($start, $end)];
        }

        $included = [];
        $restricted = [];
        $expenses = null;
        foreach ($sources as $source => [$permission, $records]) {
            if (! $actor->can($permission)) {
                $restricted[] = $source;

                continue;
            }

            $included[] = $source;
            $records = $records()->selectRaw('?::text as source', [$source]);
            $expenses = $expenses === null ? $records : $expenses->unionAll($records);
        }

        $totals = [];
        $rows = $expenses === null ? [] : DB::query()->fromSub($expenses, 'expenses')
            ->selectRaw('currency, source, SUM(amount) as amount')
            ->groupByRaw('GROUPING SETS ((currency), (currency, source))')
            ->orderBy('currency')->orderByRaw('source NULLS FIRST')->get();
        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            if ($row->source === null) {
                $totals[$currency] = ['moneda' => $currency, 'importe' => (string) $row->amount, 'por_fuente' => []];
            } else {
                $totals[$currency]['por_fuente'][] = ['fuente' => (string) $row->source, 'importe' => (string) $row->amount];
            }
        }

        return [
            'up_id' => $unitId,
            'desde' => $from,
            'hasta' => $to,
            'zona_horaria' => $timezone,
            'fuentes_incluidas' => $included,
            'fuentes_sin_permiso' => $restricted,
            'totales' => array_values($totals),
        ];
    }

    private function farmMaintenance(string $from, string $to, ?int $unitId): Builder
    {
        return DB::table('maintenances as m')->join('poultry_houses as h', 'h.id', '=', 'm.poultry_house_id')
            ->where('m.status', MaintenanceStatus::Completed->value)
            ->whereBetween('m.maintenance_date', [$from, $to])
            ->when($unitId !== null, fn (Builder $query): Builder => $query->where('h.production_unit_id', $unitId))
            ->selectRaw('m.cost_currency as currency, m.cost_amount as amount');
    }

    private function fuel(string $start, string $end, ?int $unitId): Builder
    {
        $fuel = DB::table('vehicle_fuel_entries')->where('occurred_at', '>=', $start)->where('occurred_at', '<', $end)
            ->select(['currency', 'amount']);
        if ($unitId !== null) {
            $deliveries = $this->access->within($unitId, fn (): Builder => Delivery::query()->select('deliveries.id')->toBase());
            $fuel->whereIn('usage_id', DB::table('vehicle_usages')->select('id')->whereIn('delivery_id', $deliveries));
        }

        return $fuel;
    }

    private function vehicleMaintenance(string $start, string $end): Builder
    {
        return DB::table('vehicle_maintenances')->where('status', 'completed')
            ->where('completed_at', '>=', $start)->where('completed_at', '<', $end)
            ->selectRaw('cost_currency as currency, cost_amount as amount');
    }
}
